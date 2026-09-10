package api

import (
	"context"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"encoding/json"

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

func (s *Server) handleCreateRun(w http.ResponseWriter, r *http.Request) {
	req, files, errCode, err := s.decodeRun(r)
	if err != nil {
		s.audit.Log(audit.Event{Event: audit.RunRefused, Owner: req.Owner, Image: req.Image, Reason: errCode})
		status := http.StatusBadRequest
		switch errCode {
		case contract.ErrUnauthorized:
			status = http.StatusUnauthorized
		case contract.ErrWorkspaceNotOwned:
			status = http.StatusForbidden
		case contract.ErrWorkspaceNotFound:
			status = http.StatusNotFound
		case contract.ErrWorkspaceQuota:
			status = http.StatusConflict
		case contract.ErrCapacityExceeded:
			status = http.StatusTooManyRequests
		}
		writeError(w, status, errCode, err.Error(), nil)
		return
	}
	id := ulid.Make().String()
	rec := &runRec{
		ID:     id,
		Owner:  req.Owner,
		Status: contract.StatusQueued,
		Req:    req,
		Logs:   logs.New(s.cfg.LogCapBytes),
	}
	s.mu.Lock()
	if s.running >= s.cfg.MaxConcurrent {
		if s.queued >= s.cfg.QueueMax {
			s.mu.Unlock()
			s.audit.Log(audit.Event{Event: audit.RunRefused, Owner: req.Owner, Image: req.Image, Reason: contract.ErrCapacityExceeded})
			writeError(w, http.StatusTooManyRequests, contract.ErrCapacityExceeded, "capacity exceeded", nil)
			return
		}
		s.queued++
	}
	s.runs[id] = rec
	s.mu.Unlock()

	s.audit.Log(audit.Event{Event: audit.RunAccepted, RunID: id, Owner: req.Owner, Image: req.Image, Tier: s.tier.Tier, Limits: req.Limits})
	writeJSON(w, http.StatusAccepted, contract.RunAccepted{RunID: id})
	go s.execute(rec, files)
}

func (s *Server) decodeRun(r *http.Request) (contract.RunRequest, map[string][]byte, string, error) {
	var req contract.RunRequest
	files := map[string][]byte{}
	ct := r.Header.Get("Content-Type")
	if strings.HasPrefix(ct, "multipart/") {
		if err := r.ParseMultipartForm(s.cfg.MaxRequestBytes); err != nil {
			return req, nil, contract.ErrPayloadTooLarge, err
		}
		fh, ok := r.MultipartForm.File["request.json"]
		if !ok || len(fh) == 0 {
			return req, nil, contract.ErrUnknownField, errf("request.json part required")
		}
		f, err := fh[0].Open()
		if err != nil {
			return req, nil, contract.ErrUnknownField, err
		}
		defer f.Close()
		if err := contract.DecodeJSONLimited(f, s.cfg.MaxRequestBytes, &req); err != nil {
			return req, nil, contract.ErrUnknownField, err
		}
		for name, headers := range r.MultipartForm.File {
			if name == "request.json" {
				continue
			}
			if !names.FileName(name) {
				return req, nil, contract.ErrBadFileName, errf("bad file name")
			}
			b, err := readPart(headers[0])
			if err != nil {
				return req, nil, contract.ErrPayloadTooLarge, err
			}
			files[name] = b
		}
	} else {
		if err := contract.DecodeJSONLimited(r.Body, s.cfg.MaxRequestBytes, &req); err != nil {
			return req, nil, contract.ErrUnknownField, err
		}
	}
	if code, err := s.validateRun(&req, files); err != nil {
		return req, nil, code, err
	}
	return req, files, "", nil
}

func (s *Server) validateRun(req *contract.RunRequest, files map[string][]byte) (string, error) {
	if req.Protocol != contract.Protocol {
		return contract.ErrInvalidProtocol, errf("protocol must be 1")
	}
	if strings.TrimSpace(req.Owner) == "" {
		return contract.ErrMissingOwner, errf("owner is required")
	}
	img, ok := s.images.Lookup(req.Image)
	if !ok {
		return contract.ErrUnknownImage, errf("unknown image key")
	}
	_ = img
	if !s.images.ProgramAllowed(req.Image, req.Entry.Program) {
		return contract.ErrProgramNotAllowed, errf("program not allowed")
	}
	if code, err := s.checkLimits(req.Limits); err != nil {
		return code, err
	}
	if len(req.Files) > s.cfg.MaxFiles {
		return contract.ErrTooManyFiles, errf("too many files")
	}
	for _, f := range req.Files {
		if !names.FileName(f.Name) {
			return contract.ErrBadFileName, errf("bad file name")
		}
	}
	kind := req.Workspace.Kind
	if kind == "" {
		kind = contract.WorkspaceKindRun
		req.Workspace.Kind = kind
	}
	if kind != contract.WorkspaceKindRun && kind != contract.WorkspaceKindUser {
		return contract.ErrInvalidWorkspace, errf("workspace.kind must be run or user")
	}
	if kind == contract.WorkspaceKindUser {
		if req.Workspace.ID == "" {
			return contract.ErrWorkspaceNotFound, errf("workspace id required")
		}
		if _, err := s.ws.AssertOwner(req.Workspace.ID, req.Owner); err != nil {
			if err == workspace.ErrNotOwned {
				return contract.ErrWorkspaceNotOwned, err
			}
			return contract.ErrWorkspaceNotFound, err
		}
		var extra int64
		for _, b := range files {
			extra += int64(len(b))
		}
		exceed, err := s.ws.WouldExceed(req.Workspace.ID, extra)
		if err != nil {
			return contract.ErrWorkspaceNotFound, err
		}
		if exceed {
			return contract.ErrWorkspaceQuota, errf("workspace quota exceeded")
		}
	}
	if _, err := egress.Validate(s.egress, req.Egress); err != nil {
		if ref, ok := err.(*egress.Refused); ok {
			s.audit.Log(audit.Event{Event: audit.EgressRefused, Owner: req.Owner, Image: req.Image, Reason: ref.Code, EgressHosts: hostNames(req.Egress)})
			return ref.Code, err
		}
		return contract.ErrEgressNotAllowed, err
	}
	return "", nil
}

func (s *Server) checkLimits(l contract.Limits) (string, error) {
	if l.TimeoutSec > s.cfg.MaxTimeoutSec || l.MemoryMb > s.cfg.MaxMemoryMb ||
		l.CPU > s.cfg.MaxCPU || l.Pids > s.cfg.MaxPids || l.OutputMb > s.cfg.MaxOutputMb {
		return contract.ErrLimitsExceedCaps, errf("requested limits exceed instance caps")
	}
	if l.TimeoutSec <= 0 || l.MemoryMb <= 0 || l.CPU <= 0 || l.Pids <= 0 || l.OutputMb <= 0 {
		return contract.ErrLimitsExceedCaps, errf("limits must be positive")
	}
	return "", nil
}

func (s *Server) execute(rec *runRec, files map[string][]byte) {
	ctx := context.Background()
	eg, err := egress.Validate(s.egress, rec.Req.Egress)
	if err != nil {
		s.finish(rec, contract.StatusFailed, -1, contract.ErrEgressNotAllowed)
		return
	}
	img, _ := s.images.Lookup(rec.Req.Image)
	scratchRoot := s.scratchFor(rec.ID)
	layout, err := runner.EnsureScratch(scratchRoot)
	if err != nil {
		s.finish(rec, contract.StatusFailed, -1, contract.ReasonProgramError)
		return
	}
	rec.Scratch = scratchRoot
	for name, body := range files {
		_ = os.WriteFile(filepath.Join(layout.Work, name), body, 0o640)
	}
	wsHost := ""
	if rec.Req.Workspace.Kind == contract.WorkspaceKindUser {
		wsHost = s.ws.HostPath(rec.Req.Workspace.ID)
		_ = s.ws.TouchLastUsed(rec.Req.Workspace.ID)
	}

	s.mu.Lock()
	s.running++
	if s.queued > 0 {
		s.queued--
	}
	rec.Status = contract.StatusRunning
	rec.StartedAt = time.Now().UTC()
	s.mu.Unlock()
	s.audit.Log(audit.Event{Event: audit.RunStarted, RunID: rec.ID, Owner: rec.Owner, Image: rec.Req.Image, Tier: s.tier.Tier, Limits: rec.Req.Limits})

	if !s.docker.Available() {
		s.finish(rec, contract.StatusFailed, -1, contract.ErrDockerUnavailable)
		return
	}

	timeout := time.Duration(rec.Req.Limits.TimeoutSec) * time.Second
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	cmd := append([]string{rec.Req.Entry.Program}, rec.Req.Entry.Args...)
	id, err := s.docker.Create(cctx, runner.Spec{
		ImageRef:      img.Ref,
		Cmd:           cmd,
		WorkHost:      layout.Work,
		OutHost:       layout.Out,
		WorkspaceHost: wsHost,
		Limits:        rec.Req.Limits,
		Runtime:       s.tier.Runtime,
		NetworkMode:   eg.Decision.NetworkMode,
		Env:           eg.Decision.Env,
	})
	if err != nil {
		reason := contract.ReasonProgramError
		if cctx.Err() != nil {
			reason = contract.ReasonTimeout
		}
		s.finish(rec, contract.StatusFailed, -1, reason)
		return
	}
	rec.Container = id
	code, err := s.docker.Wait(cctx, id)
	if err != nil && cctx.Err() != nil {
		_ = s.docker.Kill(context.Background(), id)
		_ = s.docker.Remove(context.Background(), id)
		s.finish(rec, contract.StatusFailed, -1, contract.ReasonTimeout)
		return
	}
	_ = s.docker.Remove(context.Background(), id)
	if err != nil {
		s.finish(rec, contract.StatusFailed, code, contract.ReasonProgramError)
		return
	}
	if code != 0 {
		s.finish(rec, contract.StatusFailed, code, contract.ReasonProgramError)
		return
	}
	s.finish(rec, contract.StatusSucceeded, code, "")
}

func (s *Server) finish(rec *runRec, status string, exit int, reason string) {
	now := time.Now().UTC()
	s.mu.Lock()
	rec.Status = status
	rec.Reason = reason
	if rec.StartedAt.IsZero() {
		rec.StartedAt = now
	}
	rec.FinishedAt = now
	if exit >= 0 {
		rec.ExitCode = &exit
	}
	if s.running > 0 {
		s.running--
	}
	s.mu.Unlock()
	var dur int64
	if !rec.StartedAt.IsZero() {
		dur = rec.FinishedAt.Sub(rec.StartedAt).Milliseconds()
	}
	s.audit.Log(audit.Event{
		Event:      audit.RunFinished,
		RunID:      rec.ID,
		Owner:      rec.Owner,
		Image:      rec.Req.Image,
		Tier:       s.tier.Tier,
		Limits:     rec.Req.Limits,
		ExitCode:   rec.ExitCode,
		Reason:     reason,
		DurationMs: &dur,
	})
}

func (s *Server) handleGetRun(w http.ResponseWriter, r *http.Request) {
	rec := s.getRun(r.PathValue("id"))
	if rec == nil {
		writeError(w, http.StatusNotFound, "run_not_found", "run not found", nil)
		return
	}
	var dur *int64
	if !rec.FinishedAt.IsZero() {
		ms := rec.FinishedAt.Sub(rec.StartedAt).Milliseconds()
		dur = &ms
	}
	truncOut, truncErr := false, false
	if rec.Logs != nil {
		truncOut, truncErr = rec.Logs.Truncated()
	}
	st := contract.RunStatus{
		RunID:      rec.ID,
		Status:     rec.Status,
		ExitCode:   rec.ExitCode,
		Reason:     rec.Reason,
		DurationMs: dur,
		Truncated:  contract.Truncated{Stdout: truncOut, Stderr: truncErr},
	}
	if !rec.StartedAt.IsZero() {
		st.StartedAt = rec.StartedAt.Format(time.RFC3339Nano)
	}
	if !rec.FinishedAt.IsZero() {
		st.FinishedAt = rec.FinishedAt.Format(time.RFC3339Nano)
	}
	writeJSON(w, http.StatusOK, st)
}

func (s *Server) handleRunLogs(w http.ResponseWriter, r *http.Request) {
	rec := s.getRun(r.PathValue("id"))
	if rec == nil {
		writeError(w, http.StatusNotFound, "run_not_found", "run not found", nil)
		return
	}
	w.Header().Set("Content-Type", "text/event-stream")
	w.WriteHeader(http.StatusOK)
	out, errb, _, _ := rec.Logs.Snapshot()
	if len(out) > 0 {
		_, _ = io.WriteString(w, "event: stdout\ndata: {\"seq\":1,\"text\":")
		_, _ = io.WriteString(w, quoteJSON(string(out)))
		_, _ = io.WriteString(w, "}\n\n")
	}
	if len(errb) > 0 {
		_, _ = io.WriteString(w, "event: stderr\ndata: {\"seq\":2,\"text\":")
		_, _ = io.WriteString(w, quoteJSON(string(errb)))
		_, _ = io.WriteString(w, "}\n\n")
	}
	_, _ = io.WriteString(w, "event: done\ndata: {\"status\":\""+rec.Status+"\"}\n\n")
}

func (s *Server) handleListArtefacts(w http.ResponseWriter, r *http.Request) {
	rec := s.getRun(r.PathValue("id"))
	if rec == nil {
		writeError(w, http.StatusNotFound, "run_not_found", "run not found", nil)
		return
	}
	items, err := artefact.List(filepath.Join(rec.Scratch, "out"), int64(rec.Req.Limits.OutputMb)*1024*1024, s.cfg.ArtefactMIMEAllow)
	if err != nil {
		writeJSON(w, http.StatusOK, []contract.Artefact{})
		return
	}
	writeJSON(w, http.StatusOK, artefact.ToContract(items))
}

func (s *Server) handleGetArtefact(w http.ResponseWriter, r *http.Request) {
	rec := s.getRun(r.PathValue("id"))
	if rec == nil {
		writeError(w, http.StatusNotFound, "run_not_found", "run not found", nil)
		return
	}
	name := r.PathValue("name")
	f, err := artefact.Open(filepath.Join(rec.Scratch, "out"), name)
	if err != nil {
		writeError(w, http.StatusNotFound, "artefact_not_found", "artefact not found", nil)
		return
	}
	defer f.Close()
	w.Header().Set("Content-Disposition", "attachment; filename=\""+name+"\"")
	http.ServeContent(w, r, name, time.Now(), f)
}

func (s *Server) handleDeleteRun(w http.ResponseWriter, r *http.Request) {
	rec := s.getRun(r.PathValue("id"))
	if rec == nil {
		writeError(w, http.StatusNotFound, "run_not_found", "run not found", nil)
		return
	}
	if rec.Container != "" && s.docker.Available() {
		_ = s.docker.Kill(r.Context(), rec.Container)
		_ = s.docker.Remove(r.Context(), rec.Container)
	}
	if rec.Scratch != "" {
		_ = os.RemoveAll(rec.Scratch)
	}
	s.finish(rec, contract.StatusCancelled, -1, contract.ReasonCancelled)
	s.audit.Log(audit.Event{Event: audit.RunCancelled, RunID: rec.ID, Owner: rec.Owner, Image: rec.Req.Image, Tier: s.tier.Tier})
	w.WriteHeader(http.StatusNoContent)
}

func readPart(h *multipart.FileHeader) ([]byte, error) {
	f, err := h.Open()
	if err != nil {
		return nil, err
	}
	defer f.Close()
	return io.ReadAll(f)
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

func quoteJSON(s string) string {
	b, err := json.Marshal(s)
	if err != nil {
		return `""`
	}
	return string(b)
}
