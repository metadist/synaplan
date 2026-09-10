package api

import (
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/metadist/synaplan-compute/internal/audit"
	"github.com/metadist/synaplan-compute/internal/auth"
	"github.com/metadist/synaplan-compute/internal/egress"
	"github.com/metadist/synaplan-compute/internal/images"
	"github.com/metadist/synaplan-compute/internal/logs"
	"github.com/metadist/synaplan-compute/internal/runner"
	rt "github.com/metadist/synaplan-compute/internal/runtime"
	"github.com/metadist/synaplan-compute/internal/workspace"
	"github.com/metadist/synaplan-compute/pkg/config"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

// Server is the protocol 1 HTTP API.
type Server struct {
	cfg    *config.Config
	auth   *auth.Bearer
	images *images.Map
	docker *runner.Docker
	ws     *workspace.Store
	audit  *audit.Logger
	tier   rt.Selection
	egress egress.Config

	mu      sync.Mutex
	runs    map[string]*runRec
	running int
	queued  int
}

type runRec struct {
	ID         string
	Owner      string
	Status     string
	ExitCode   *int
	Reason     string
	StartedAt  time.Time
	FinishedAt time.Time
	Req        contract.RunRequest
	Scratch    string
	Logs       *logs.Stream
	Container  string
}

// Options configures New.
type Options struct {
	Config *config.Config
	Docker *runner.Docker
	Images *images.Map
	Store  *workspace.Store
	Audit  *audit.Logger
	Tier   rt.Selection
}

// New builds the mux. Docker may be unavailable; health still serves.
func New(opt Options) (*Server, error) {
	if opt.Config == nil {
		return nil, errors.New("config required")
	}
	b, err := auth.New(opt.Config.AuthToken)
	if err != nil {
		return nil, err
	}
	imgs := opt.Images
	if imgs == nil {
		imgs = images.Default()
	}
	if err := imgs.RequireDigests(); err != nil {
		return nil, err
	}
	ws := opt.Store
	if ws == nil {
		ws, err = workspace.New(opt.Config.WorkspacesDir)
		if err != nil {
			return nil, err
		}
	}
	aud := opt.Audit
	if aud == nil {
		aud = audit.New(os.Stdout)
	}
	d := opt.Docker
	if d == nil {
		d = &runner.Docker{}
	}
	s := &Server{
		cfg:    opt.Config,
		auth:   b,
		images: imgs,
		docker: d,
		ws:     ws,
		audit:  aud,
		tier:   opt.Tier,
		egress: egress.Config{Enabled: opt.Config.EgressEnabled, MaxHosts: opt.Config.EgressMaxHosts},
		runs:   make(map[string]*runRec),
	}
	if s.tier.Tier == "" {
		s.tier.Tier = rt.TierDocker
	}
	return s, nil
}

// Handler is the authenticated mux. GET /v1/health is public.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /v1/health", s.handleHealth)
	mux.HandleFunc("POST /v1/runs", s.handleCreateRun)
	mux.HandleFunc("GET /v1/runs/{id}", s.handleGetRun)
	mux.HandleFunc("GET /v1/runs/{id}/logs", s.handleRunLogs)
	mux.HandleFunc("GET /v1/runs/{id}/artefacts", s.handleListArtefacts)
	mux.HandleFunc("GET /v1/runs/{id}/artefacts/{name}", s.handleGetArtefact)
	mux.HandleFunc("DELETE /v1/runs/{id}", s.handleDeleteRun)
	mux.HandleFunc("POST /v1/workspaces", s.handleCreateWorkspace)
	mux.HandleFunc("GET /v1/workspaces/{id}/usage", s.handleWorkspaceUsage)
	mux.HandleFunc("GET /v1/workspaces/{id}/files", s.handleWorkspaceFiles)
	mux.HandleFunc("GET /v1/workspaces/{id}/files/{path...}", s.handleWorkspaceFile)
	mux.HandleFunc("DELETE /v1/workspaces/{id}", s.handleDeleteWorkspace)
	return s.auth.Wrap(mux)
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	s.mu.Lock()
	running, queued := s.running, s.queued
	s.mu.Unlock()
	imgs := s.images.List()
	hi := make([]contract.HealthImage, 0, len(imgs))
	for _, im := range imgs {
		hi = append(hi, contract.HealthImage{Key: im.Key, Ref: im.Ref})
	}
	writeJSON(w, http.StatusOK, contract.Health{
		Protocol: contract.Protocol,
		Tier:     s.tier.Tier,
		Images:   hi,
		Capacity: contract.HealthCapacity{
			MaxConcurrent: s.cfg.MaxConcurrent,
			Running:       running,
			Queued:        queued,
		},
		Caps: contract.HealthCaps{
			TimeoutSec: s.cfg.MaxTimeoutSec,
			MemoryMb:   s.cfg.MaxMemoryMb,
			CPU:        s.cfg.MaxCPU,
			Pids:       s.cfg.MaxPids,
			OutputMb:   s.cfg.MaxOutputMb,
		},
		Features: contract.HealthFeatures{
			Workspaces: true,
			Egress:     s.cfg.EgressEnabled,
		},
	})
}

func (s *Server) scratchFor(id string) string {
	return filepath.Join(s.cfg.ScratchDir, id)
}

func (s *Server) getRun(id string) *runRec {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.runs[id]
}
