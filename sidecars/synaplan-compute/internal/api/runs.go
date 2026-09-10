package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/metadist/synaplan-compute/internal/artefact"
	"github.com/metadist/synaplan-compute/internal/audit"
	"github.com/metadist/synaplan-compute/internal/egress"
	"github.com/metadist/synaplan-compute/internal/logs"
	"github.com/metadist/synaplan-compute/internal/names"
	"github.com/metadist/synaplan-compute/internal/runner"
	"github.com/metadist/synaplan-compute/internal/workspace"
	"github.com/metadist/synaplan-compute/pkg/contract"
	"github.com/oklog/ulid/v2"
)

const (
	requestPart = "request.json"
	// exitKilled is the shell convention for SIGKILL (128+9); the kernel OOM
	// killer and our timeout both produce it, so OOMKilled disambiguates.
	exitKilled = 137
	// logDrain bounds how long execute waits for the log follower after the
	// container stopped before dropping the remaining bytes.
	logDrain = 3 * time.Second
	// cleanupTimeout bounds kill/inspect/remove after the run context ended.
	cleanupTimeout = 15 * time.Second
)

func (s *Server) handleCreateRun(w http.ResponseWriter, r *http.Request) {
	req, files, errCode, details, err := s.decodeRun(w, r)
	if err != nil {
		s.audit.Log(audit.Event{Event: audit.RunRefused, Owner: req.Owner, Image: req.Image, Reason: errCode})
		writeError(w, statusFor(errCode), errCode, err.Error(), details)
		return
	}
	rec := &runRec{
		ID:     ulid.Make().String(),
		Owner:  req.Owner,
		Status: contract.StatusQueued,
		Req:    req,
		Logs:   logs.New(s.cfg.LogCapBytes),
	}
	for _, b := range files {
		rec.BytesIn += int64(len(b))
	}
	rec.ctx, rec.cancel = context.WithCancel(context.Background())

	s.mu.Lock()
	if s.running+s.queued >= s.cfg.MaxConcurrent+s.cfg.QueueMax {
		s.mu.Unlock()
		rec.cancel()
		s.audit.Log(audit.Event{Event: audit.RunRefused, Owner: req.Owner, Image: req.Image, Reason: contract.ErrCapacityExceeded})
		writeError(w, http.StatusTooManyRequests, contract.ErrCapacityExceeded, "capacity exceeded", nil)
		return
	}
	s.queued++
	rec.active = true
	s.runs[rec.ID] = rec
	s.mu.Unlock()

	s.audit.Log(audit.Event{Event: audit.RunAccepted, RunID: rec.ID, Owner: req.Owner, Image: req.Image, Tier: s.tier.Tier, Limits: req.Limits})
	writeJSON(w, http.StatusAccepted, contract.RunAccepted{RunID: rec.ID})
	go s.execute(rec, files)
}

func statusFor(code string) int {
	switch code {
	case contract.ErrUnauthorized:
		return http.StatusUnauthorized
	case contract.ErrWorkspaceNotOwned:
		return http.StatusForbidden
	case contract.ErrWorkspaceNotFound:
		return http.StatusNotFound
	case contract.ErrWorkspaceQuota:
		return http.StatusConflict
	case contract.ErrCapacityExceeded:
		return http.StatusTooManyRequests
	case contract.ErrPayloadTooLarge:
		return http.StatusRequestEntityTooLarge
	case contract.ErrInternal:
		return http.StatusInternalServerError
	default:
		return http.StatusBadRequest
	}
}

// decodeRun reads either a JSON body or a multipart form whose request.json
// part carries the RunRequest and whose other parts are exactly the files
// declared in req.files. The body is bounded by MaxRequestBytes and the
// number of file parts by MaxFiles.
func (s *Server) decodeRun(w http.ResponseWriter, r *http.Request) (contract.RunRequest, map[string][]byte, string, any, error) {
	var req contract.RunRequest
	files := map[string][]byte{}
	if r.ContentLength > s.cfg.MaxRequestBytes {
		return req, nil, contract.ErrPayloadTooLarge, nil, errf("request body exceeds the configured maximum")
	}
	r.Body = http.MaxBytesReader(w, r.Body, s.cfg.MaxRequestBytes)
	ct := r.Header.Get("Content-Type")
	if strings.HasPrefix(ct, "multipart/") {
		code, err := s.readMultipart(r, &req, files)
		if err != nil {
			return req, nil, code, nil, err
		}
	} else if err := contract.DecodeJSONLimited(r.Body, s.cfg.MaxRequestBytes, &req); err != nil {
		return req, nil, bodyErrCode(err), nil, err
	}
	code, details, err := s.validateRun(&req, files)
	if err != nil {
		return req, nil, code, details, err
	}
	return req, files, "", nil, nil
}

func (s *Server) readMultipart(r *http.Request, req *contract.RunRequest, files map[string][]byte) (string, error) {
	mr, err := r.MultipartReader()
	if err != nil {
		return contract.ErrInvalidJSON, fmt.Errorf("malformed multipart body: %w", err)
	}
	haveReq := false
	fileParts := 0
	for {
		part, err := mr.NextPart()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return bodyErrCode(err), fmt.Errorf("malformed multipart body: %w", err)
		}
		name := part.FormName()
		if name == requestPart {
			if haveReq {
				_ = part.Close()
				return contract.ErrInvalidJSON, errf("duplicate request.json part")
			}
			haveReq = true
			if err := contract.DecodeJSONLimited(part, s.cfg.MaxRequestBytes, req); err != nil {
				_ = part.Close()
				return bodyErrCode(err), err
			}
			_ = part.Close()
			continue
		}
		fileParts++
		if fileParts > s.cfg.MaxFiles {
			_ = part.Close()
			return contract.ErrTooManyFiles, errf("too many file parts")
		}
		if !names.FileName(name) {
			_ = part.Close()
			return contract.ErrBadFileName, errf("bad file name")
		}
		if _, dup := files[name]; dup {
			_ = part.Close()
			return contract.ErrBadFileName, errf("duplicate file part")
		}
		b, err := readPart(part)
		if err != nil {
			return bodyErrCode(err), err
		}
		files[name] = b
	}
	if !haveReq {
		return contract.ErrInvalidJSON, errf("request.json part required")
	}
	declared := make(map[string]bool, len(req.Files))
	for _, f := range req.Files {
		declared[f.Name] = true
	}
	for name := range files {
		if !declared[name] {
			return contract.ErrBadFileName, fmt.Errorf("file part %q is not declared in files", name)
		}
	}
	return "", nil
}

// bodyErrCode maps body read/decode failures to protocol codes.
func bodyErrCode(err error) string {
	var tooLarge *http.MaxBytesError
	if errors.As(err, &tooLarge) || strings.Contains(err.Error(), "request body too large") {
		return contract.ErrPayloadTooLarge
	}
	if code := contract.DecodeCode(err); code != "" {
		return code
	}
	return contract.ErrInvalidJSON
}

func (s *Server) validateRun(req *contract.RunRequest, files map[string][]byte) (string, any, error) {
	if req.Protocol != contract.Protocol {
		return contract.ErrInvalidProtocol, nil, errf("protocol must be 1")
	}
	if strings.TrimSpace(req.Owner) == "" {
		return contract.ErrMissingOwner, nil, errf("owner is required")
	}
	if _, ok := s.images.Lookup(req.Image); !ok {
		return contract.ErrUnknownImage, nil, errf("unknown image key")
	}
	if !s.images.ProgramAllowed(req.Image, req.Entry.Program) {
		return contract.ErrProgramNotAllowed, nil, errf("program not allowed")
	}
	if code, details, err := s.checkLimits(req.Limits); err != nil {
		return code, details, err
	}
	if len(req.Files) > s.cfg.MaxFiles {
		return contract.ErrTooManyFiles, nil, errf("too many files")
	}
	for _, f := range req.Files {
		if !names.FileName(f.Name) {
			return contract.ErrBadFileName, nil, errf("bad file name")
		}
	}
	kind := req.Workspace.Kind
	if kind == "" {
		kind = contract.WorkspaceKindRun
		req.Workspace.Kind = kind
	}
	if kind != contract.WorkspaceKindRun && kind != contract.WorkspaceKindUser {
		return contract.ErrInvalidWorkspace, nil, errf("workspace.kind must be run or user")
	}
	if kind == contract.WorkspaceKindUser {
		if req.Workspace.ID == "" {
			return contract.ErrWorkspaceNotFound, nil, errf("workspace id required")
		}
		if _, err := s.ws.AssertOwner(req.Workspace.ID, req.Owner); err != nil {
			if err == workspace.ErrNotOwned {
				return contract.ErrWorkspaceNotOwned, nil, err
			}
			return contract.ErrWorkspaceNotFound, nil, err
		}
		var extra int64
		for _, b := range files {
			extra += int64(len(b))
		}
		exceed, err := s.ws.WouldExceed(req.Workspace.ID, extra)
		if err != nil {
			return contract.ErrWorkspaceNotFound, nil, err
		}
		if exceed {
			return contract.ErrWorkspaceQuota, nil, errf("workspace quota exceeded")
		}
	}
	if err := egress.Validate(s.egress, req.Egress); err != nil {
		code := contract.ErrEgressNotAllowed
		if ref, ok := err.(*egress.Refused); ok {
			code = ref.Code
		}
		s.audit.Log(audit.Event{Event: audit.EgressRefused, Owner: req.Owner, Image: req.Image, Reason: code, EgressHosts: hostNames(req.Egress)})
		return code, nil, err
	}
	return "", nil, nil
}

func (s *Server) checkLimits(l contract.Limits) (string, any, error) {
	checks := []struct {
		field     string
		requested float64
		cap       float64
	}{
		{"timeoutSec", float64(l.TimeoutSec), float64(s.cfg.MaxTimeoutSec)},
		{"memoryMb", float64(l.MemoryMb), float64(s.cfg.MaxMemoryMb)},
		{"cpu", l.CPU, s.cfg.MaxCPU},
		{"pids", float64(l.Pids), float64(s.cfg.MaxPids)},
		{"outputMb", float64(l.OutputMb), float64(s.cfg.MaxOutputMb)},
	}
	for _, c := range checks {
		if c.requested > c.cap {
			return contract.ErrLimitsExceedCaps, contract.LimitDetail{Field: c.field, Requested: c.requested, Cap: c.cap},
				errf("requested limits exceed instance caps")
		}
		if c.requested <= 0 {
			return contract.ErrLimitsExceedCaps, contract.LimitDetail{Field: c.field, Requested: c.requested, Cap: c.cap},
				errf("limits must be positive")
		}
	}
	return "", nil, nil
}

// execute owns the run from acceptance to container removal. Every status
// transition goes through finish; the semaphore token and the running
// counter are released exactly once in exitExecute.
func (s *Server) execute(rec *runRec, files map[string][]byte) {
	defer s.exitExecute(rec)

	select {
	case s.sem <- struct{}{}:
	case <-rec.ctx.Done():
		return
	}
	s.mu.Lock()
	rec.acquired = true
	s.queued--
	s.running++
	cancelled := rec.done || rec.ctx.Err() != nil
	if !cancelled {
		rec.Status = contract.StatusRunning
		rec.StartedAt = time.Now().UTC()
	}
	s.mu.Unlock()
	if cancelled {
		return
	}
	s.audit.Log(audit.Event{Event: audit.RunStarted, RunID: rec.ID, Owner: rec.Owner, Image: rec.Req.Image, Tier: s.tier.Tier, Limits: rec.Req.Limits})

	if !s.docker.Available() {
		s.finish(rec, contract.StatusFailed, -1, contract.ErrDockerUnavailable)
		return
	}
	layout, err := s.prepareScratch(rec, files)
	if err != nil {
		s.finish(rec, contract.StatusFailed, -1, contract.ErrInternal)
		return
	}
	wsHost := ""
	if rec.Req.Workspace.Kind == contract.WorkspaceKindUser {
		wsHost = s.ws.HostPath(rec.Req.Workspace.ID)
		_ = s.ws.TouchLastUsed(rec.Req.Workspace.ID)
	}
	img, _ := s.images.Lookup(rec.Req.Image)

	timeout := time.Duration(rec.Req.Limits.TimeoutSec) * time.Second
	cctx, cancel := context.WithTimeout(rec.ctx, timeout)
	defer cancel()

	// A DELETE that raced the queue must never reach ContainerCreate.
	if rec.ctx.Err() != nil {
		return
	}
	id, err := s.docker.Create(cctx, runner.Spec{
		ImageRef:      img.Ref,
		Cmd:           append([]string{rec.Req.Entry.Program}, rec.Req.Entry.Args...),
		WorkHost:      layout.Work,
		OutHost:       layout.Out,
		WorkspaceHost: wsHost,
		Limits:        rec.Req.Limits,
		Runtime:       s.tier.Runtime,
		User:          s.sandboxUser(),
	})
	if err != nil {
		if rec.ctx.Err() != nil {
			return
		}
		reason := contract.ReasonProgramError
		if cctx.Err() != nil {
			reason = contract.ReasonTimeout
		}
		s.finish(rec, contract.StatusFailed, -1, reason)
		return
	}
	s.mu.Lock()
	rec.Container = id
	cancelled = rec.done
	s.mu.Unlock()
	if cancelled {
		s.destroy(id)
		return
	}

	logCtx, logCancel := context.WithCancel(context.Background())
	logDone := make(chan struct{})
	go func() {
		defer close(logDone)
		_ = s.docker.Logs(logCtx, id, streamWriter{rec.Logs, "stdout"}, streamWriter{rec.Logs, "stderr"})
	}()

	code, werr := s.docker.Wait(cctx, id)
	cancelled = rec.ctx.Err() != nil
	timedOut := !cancelled && werr != nil && cctx.Err() != nil
	deadlinePassed := cctx.Err() != nil
	if werr != nil {
		kctx, kcancel := context.WithTimeout(context.Background(), cleanupTimeout)
		_ = s.docker.Kill(kctx, id)
		kcancel()
	}
	select {
	case <-logDone:
	case <-time.After(logDrain):
	}
	logCancel()
	<-logDone

	ictx, icancel := context.WithTimeout(context.Background(), cleanupTimeout)
	state, ierr := s.docker.Inspect(ictx, id)
	_ = s.docker.Remove(ictx, id)
	icancel()

	bytesOut := artefact.TotalBytes(layout.Out)
	total := bytesOut + artefact.TotalBytes(layout.Work)
	s.mu.Lock()
	rec.BytesOut = bytesOut
	s.mu.Unlock()

	switch {
	case cancelled:
		return
	case timedOut:
		s.finish(rec, contract.StatusFailed, -1, contract.ReasonTimeout)
	case werr != nil:
		s.finish(rec, contract.StatusFailed, -1, contract.ReasonProgramError)
	case ierr == nil && state.OOMKilled:
		s.finish(rec, contract.StatusFailed, code, contract.ReasonOOM)
	case total > int64(rec.Req.Limits.OutputMb)*1024*1024:
		s.finish(rec, contract.StatusFailed, code, contract.ReasonOutputLimit)
	case code == exitKilled && deadlinePassed:
		s.finish(rec, contract.StatusFailed, code, contract.ReasonTimeout)
	case code != 0:
		s.finish(rec, contract.StatusFailed, code, contract.ReasonProgramError)
	default:
		s.finish(rec, contract.StatusSucceeded, code, "")
	}
}

func (s *Server) sandboxUser() string {
	if s.cfg.SandboxUID > 0 && s.cfg.SandboxGID > 0 {
		return strconv.Itoa(s.cfg.SandboxUID) + ":" + strconv.Itoa(s.cfg.SandboxGID)
	}
	return runner.DefaultSandboxUser
}

func (s *Server) prepareScratch(rec *runRec, files map[string][]byte) (runner.ScratchLayout, error) {
	root := s.scratchFor(rec.ID)
	layout, err := runner.EnsureScratch(root, s.owner)
	if err != nil {
		return layout, err
	}
	s.mu.Lock()
	rec.Scratch = root
	s.mu.Unlock()
	for name, body := range files {
		if err := s.owner.WriteFile(filepath.Join(layout.Work, name), body); err != nil {
			return layout, err
		}
	}
	return layout, nil
}

// destroy kills and removes a container whose run was cancelled.
func (s *Server) destroy(id string) {
	ctx, cancel := context.WithTimeout(context.Background(), cleanupTimeout)
	defer cancel()
	_ = s.docker.Kill(ctx, id)
	_ = s.docker.Remove(ctx, id)
}

// exitExecute releases the concurrency slot, guarantees a terminal status,
// and removes scratch if DELETE asked for it while the run was active.
func (s *Server) exitExecute(rec *runRec) {
	s.mu.Lock()
	if rec.acquired {
		s.running--
	} else {
		s.queued--
	}
	acquired := rec.acquired
	rec.active = false
	done := rec.done
	s.mu.Unlock()
	if acquired {
		<-s.sem
	}
	rec.cancel()
	if !done {
		s.finish(rec, contract.StatusFailed, -1, contract.ErrInternal)
	}
	s.mu.Lock()
	purge, scratch := rec.purge, rec.Scratch
	if purge {
		rec.purged = true
	}
	s.mu.Unlock()
	if purge && scratch != "" {
		_ = os.RemoveAll(scratch)
	}
}

// finish records the terminal status once; later calls are no-ops so a
// cancelled run never flips to failed.
func (s *Server) finish(rec *runRec, status string, exit int, reason string) {
	s.mu.Lock()
	v, first := s.finishLocked(rec, status, exit, reason)
	s.mu.Unlock()
	if first {
		s.auditFinished(v)
	}
}

// finishLocked is finish with s.mu held; it reports whether this call was
// the one that terminated the run.
func (s *Server) finishLocked(rec *runRec, status string, exit int, reason string) (runView, bool) {
	if rec.done {
		return runView{}, false
	}
	now := time.Now().UTC()
	rec.done = true
	rec.Status = status
	rec.Reason = reason
	if rec.StartedAt.IsZero() {
		rec.StartedAt = now
	}
	rec.FinishedAt = now
	if exit >= 0 {
		rec.ExitCode = &exit
	}
	return rec.view(), true
}

func (s *Server) auditFinished(v runView) {
	dur := v.FinishedAt.Sub(v.StartedAt).Milliseconds()
	s.audit.Log(audit.Event{
		Event:      audit.RunFinished,
		RunID:      v.ID,
		Owner:      v.Owner,
		Image:      v.Req.Image,
		Tier:       s.tier.Tier,
		Limits:     v.Req.Limits,
		ExitCode:   v.ExitCode,
		Reason:     v.Reason,
		DurationMs: &dur,
		BytesIn:    &v.BytesIn,
		BytesOut:   &v.BytesOut,
	})
}

func (s *Server) handleGetRun(w http.ResponseWriter, r *http.Request) {
	v, ok := s.getView(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, contract.ErrRunNotFound, "run not found", nil)
		return
	}
	writeJSON(w, http.StatusOK, statusOf(v))
}

func statusOf(v runView) contract.RunStatus {
	var dur *int64
	var wall int64
	if !v.FinishedAt.IsZero() {
		ms := v.FinishedAt.Sub(v.StartedAt).Milliseconds()
		dur = &ms
		wall = ms
	} else if !v.StartedAt.IsZero() {
		wall = time.Since(v.StartedAt).Milliseconds()
	}
	truncOut, truncErr := false, false
	if v.Logs != nil {
		truncOut, truncErr = v.Logs.Truncated()
	}
	st := contract.RunStatus{
		RunID:      v.ID,
		Status:     v.Status,
		ExitCode:   v.ExitCode,
		Reason:     v.Reason,
		DurationMs: dur,
		Usage:      contract.Usage{WallMs: wall, BytesIn: v.BytesIn, BytesOut: v.BytesOut},
		Truncated:  contract.Truncated{Stdout: truncOut, Stderr: truncErr},
	}
	if !v.StartedAt.IsZero() {
		st.StartedAt = v.StartedAt.Format(time.RFC3339Nano)
	}
	if !v.FinishedAt.IsZero() {
		st.FinishedAt = v.FinishedAt.Format(time.RFC3339Nano)
	}
	return st
}

func (s *Server) handleRunLogs(w http.ResponseWriter, r *http.Request) {
	v, ok := s.getView(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, contract.ErrRunNotFound, "run not found", nil)
		return
	}
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(http.StatusOK)
	out, errb, truncOut, truncErr := v.Logs.Snapshot()
	id := 0
	emit := func(event string, data any) {
		id++
		b, err := json.Marshal(data)
		if err != nil {
			return
		}
		_, _ = fmt.Fprintf(w, "id: %d\nevent: %s\ndata: %s\n\n", id, event, b)
	}
	if len(out) > 0 {
		emit(contract.LogEventStdout, contract.LogChunk{Seq: 1, Text: string(out)})
	}
	if len(errb) > 0 {
		emit(contract.LogEventStderr, contract.LogChunk{Seq: 2, Text: string(errb)})
	}
	emit(contract.LogEventStatus, contract.LogStatus{Status: v.Status})
	if truncOut || truncErr {
		emit(contract.LogEventTruncated, contract.Truncated{Stdout: truncOut, Stderr: truncErr})
	}
	if !v.FinishedAt.IsZero() {
		emit(contract.LogEventDone, contract.LogDone{Status: v.Status, ExitCode: v.ExitCode, Reason: v.Reason})
	}
}

func (s *Server) handleListArtefacts(w http.ResponseWriter, r *http.Request) {
	v, ok := s.getView(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, contract.ErrRunNotFound, "run not found", nil)
		return
	}
	if status, code := artefactsBlocked(v); code != "" {
		writeError(w, status, code, "artefacts are not served for this run", nil)
		return
	}
	items, err := artefact.List(filepath.Join(v.Scratch, "out"), int64(v.Req.Limits.OutputMb)*1024*1024, s.cfg.ArtefactMIMEAllow)
	if err != nil {
		writeError(w, http.StatusInternalServerError, contract.ErrInternal, "artefacts could not be listed", nil)
		return
	}
	writeJSON(w, http.StatusOK, artefact.ToContract(items))
}

// artefactsBlocked returns the HTTP status and code that explain why a run's
// /out is not served: it exceeded outputMb (409) or has no scratch (404).
func artefactsBlocked(v runView) (int, string) {
	switch {
	case v.Reason == contract.ReasonOutputLimit:
		return http.StatusConflict, contract.ReasonOutputLimit
	case v.Purged || v.Scratch == "":
		return http.StatusNotFound, contract.ErrArtefactNotFound
	}
	return 0, ""
}

func (s *Server) handleGetArtefact(w http.ResponseWriter, r *http.Request) {
	v, ok := s.getView(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, contract.ErrRunNotFound, "run not found", nil)
		return
	}
	name := r.PathValue("name")
	if status, code := artefactsBlocked(v); code != "" {
		writeError(w, status, code, "artefacts are not served for this run", nil)
		return
	}
	f, it, err := artefact.Open(filepath.Join(v.Scratch, "out"), name, int64(v.Req.Limits.OutputMb)*1024*1024, s.cfg.ArtefactMIMEAllow)
	if err != nil {
		var ref *artefact.Refused
		if errors.As(err, &ref) {
			writeError(w, http.StatusForbidden, ref.Code, "artefact refused: "+ref.Code, nil)
			return
		}
		writeError(w, http.StatusNotFound, contract.ErrArtefactNotFound, "artefact not found", nil)
		return
	}
	defer f.Close()
	setDownloadHeaders(w, it.Mime, name, it.Size)
	http.ServeContent(w, r, "", time.Time{}, f)
}

// setDownloadHeaders forces the allow-listed MIME and disables browser
// sniffing and inline rendering.
func setDownloadHeaders(w http.ResponseWriter, mimeType, name string, size int64) {
	w.Header().Set("Content-Type", mimeType)
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Content-Disposition", "attachment; filename=\""+filepath.Base(name)+"\"")
	w.Header().Set("Cache-Control", "no-store")
	if size >= 0 {
		w.Header().Set("Content-Length", strconv.FormatInt(size, 10))
	}
}

func (s *Server) handleDeleteRun(w http.ResponseWriter, r *http.Request) {
	s.mu.Lock()
	rec, ok := s.runs[r.PathValue("id")]
	if !ok {
		s.mu.Unlock()
		writeError(w, http.StatusNotFound, contract.ErrRunNotFound, "run not found", nil)
		return
	}
	// Cancel and terminate under one lock so execute's exit path can never
	// record a different terminal status first.
	rec.cancel()
	v, first := s.finishLocked(rec, contract.StatusCancelled, -1, contract.ReasonCancelled)
	container, active, scratch := rec.Container, rec.active, rec.Scratch
	if active {
		rec.purge = true
	} else {
		rec.purged = true
	}
	if !first {
		v = rec.view()
	}
	s.mu.Unlock()

	if first {
		s.auditFinished(v)
	}
	if container != "" && s.docker.Available() {
		ctx, cancel := context.WithTimeout(context.Background(), cleanupTimeout)
		_ = s.docker.Kill(ctx, container)
		cancel()
	}
	if !active && scratch != "" {
		_ = os.RemoveAll(scratch)
	}
	s.audit.Log(audit.Event{Event: audit.RunCancelled, RunID: v.ID, Owner: v.Owner, Image: v.Req.Image, Tier: s.tier.Tier})
	w.WriteHeader(http.StatusNoContent)
}

// streamWriter appends one side of the container output to the run log.
type streamWriter struct {
	s    *logs.Stream
	kind string
}

func (w streamWriter) Write(p []byte) (int, error) {
	w.s.Append(w.kind, p)
	return len(p), nil
}

func readPart(p *multipart.Part) ([]byte, error) {
	defer p.Close()
	return io.ReadAll(p)
}

func hostNames(e contract.Egress) []string {
	out := make([]string, 0, len(e.Allow))
	for _, h := range e.Allow {
		out = append(out, h.Host)
	}
	return out
}

type errf string

func (e errf) Error() string { return string(e) }
