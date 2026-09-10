package api

import (
	"errors"
	"io"
	"net/http"
	"os"
	"time"

	"github.com/metadist/synaplan-compute/internal/audit"
	"github.com/metadist/synaplan-compute/internal/names"
	"github.com/metadist/synaplan-compute/internal/workspace"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

func (s *Server) handleCreateWorkspace(w http.ResponseWriter, r *http.Request) {
	var body contract.WorkspaceCreate
	if r.ContentLength > s.cfg.MaxRequestBytes {
		writeError(w, http.StatusRequestEntityTooLarge, contract.ErrPayloadTooLarge, "request body exceeds the configured maximum", nil)
		return
	}
	r.Body = http.MaxBytesReader(w, r.Body, s.cfg.MaxRequestBytes)
	if err := contract.DecodeJSONLimited(r.Body, s.cfg.MaxRequestBytes, &body); err != nil {
		code := bodyErrCode(err)
		writeError(w, statusFor(code), code, err.Error(), nil)
		return
	}
	if body.Owner == "" {
		writeError(w, http.StatusBadRequest, contract.ErrMissingOwner, "owner is required", nil)
		return
	}
	meta, err := s.ws.Create(body.Owner, body.QuotaMb)
	if err != nil {
		writeError(w, http.StatusInternalServerError, contract.ErrInternal, "workspace could not be created", nil)
		return
	}
	s.audit.Log(audit.Event{Event: audit.WorkspaceCreated, Owner: meta.Owner})
	writeJSON(w, http.StatusCreated, contract.WorkspaceCreated{WorkspaceID: meta.ID, QuotaMb: meta.QuotaMb})
}

func (s *Server) handleWorkspaceUsage(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	u, err := s.ws.Usage(id)
	if err != nil {
		writeWorkspaceErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, contract.WorkspaceUsage{
		UsedMb:     u.UsedMb,
		QuotaMb:    u.QuotaMb,
		FileCount:  u.FileCount,
		LastUsedAt: u.LastUsedAt.Format(time.RFC3339Nano),
	})
}

func (s *Server) handleWorkspaceFiles(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	rel := r.URL.Query().Get("path")
	files, err := s.ws.ListFiles(id, rel)
	if err != nil {
		writeWorkspaceErr(w, err)
		return
	}
	out := make([]contract.WorkspaceFile, 0, len(files))
	for _, f := range files {
		out = append(out, contract.WorkspaceFile{
			Path:       f.Path,
			Size:       f.Size,
			Mime:       f.Mime,
			ModifiedAt: f.ModifiedAt.Format(time.RFC3339Nano),
		})
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) handleWorkspaceFile(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	p := r.PathValue("path")
	if _, ok := names.RelPath(p); !ok {
		writeError(w, http.StatusBadRequest, contract.ErrBadFileName, "bad file name", nil)
		return
	}
	f, info, err := s.ws.OpenFile(id, p)
	if err != nil {
		writeWorkspaceErr(w, err)
		return
	}
	defer f.Close()
	if !s.mimeAllowed(info.Mime) {
		writeError(w, http.StatusForbidden, contract.ErrMimeNotAllowed, "file type is not on the allow-list", nil)
		return
	}
	setDownloadHeaders(w, info.Mime, info.Path, info.Size)
	w.WriteHeader(http.StatusOK)
	_, _ = io.Copy(w, f)
}

func (s *Server) mimeAllowed(m string) bool {
	if len(s.cfg.ArtefactMIMEAllow) == 0 {
		return true
	}
	for _, a := range s.cfg.ArtefactMIMEAllow {
		if a == m {
			return true
		}
	}
	return false
}

func (s *Server) handleDeleteWorkspace(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	meta, err := s.ws.Get(id)
	if err != nil {
		writeWorkspaceErr(w, err)
		return
	}
	if err := s.ws.Delete(id); err != nil {
		writeWorkspaceErr(w, err)
		return
	}
	s.audit.Log(audit.Event{Event: audit.WorkspaceDeleted, Owner: meta.Owner})
	w.WriteHeader(http.StatusNoContent)
}

func writeWorkspaceErr(w http.ResponseWriter, err error) {
	switch {
	case errors.Is(err, workspace.ErrNotOwned):
		writeError(w, http.StatusForbidden, contract.ErrWorkspaceNotOwned, err.Error(), nil)
	case errors.Is(err, workspace.ErrNotFound):
		writeError(w, http.StatusNotFound, contract.ErrWorkspaceNotFound, err.Error(), nil)
	case errors.Is(err, workspace.ErrQuota):
		writeError(w, http.StatusConflict, contract.ErrWorkspaceQuota, err.Error(), nil)
	case errors.Is(err, workspace.ErrBadName):
		writeError(w, http.StatusBadRequest, contract.ErrBadFileName, err.Error(), nil)
	case errors.Is(err, os.ErrNotExist):
		writeError(w, http.StatusNotFound, contract.ErrWorkspaceNotFound, "file not found", nil)
	default:
		writeError(w, http.StatusInternalServerError, contract.ErrInternal, "workspace operation failed", nil)
	}
}
