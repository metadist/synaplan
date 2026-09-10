package api

import (
	"context"
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
	"github.com/metadist/synaplan-compute/internal/perm"
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
	docker runner.Runner
	ws     *workspace.Store
	audit  *audit.Logger
	tier   rt.Selection
	egress egress.Config
	owner  *perm.Owner

	// sem holds one token per running container; execute blocks on it
	// before Create so MaxConcurrent is a hard bound.
	sem chan struct{}

	// mu guards runs and every runRec field. Handlers snapshot under the
	// lock and never touch a record afterwards.
	mu      sync.Mutex
	runs    map[string]*runRec
	running int
	queued  int
}

// runRec is the mutable state of one run. Logs has its own mutex.
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
	BytesIn    int64
	BytesOut   int64

	ctx      context.Context
	cancel   context.CancelFunc
	done     bool // finish has run; later calls are no-ops
	active   bool // execute goroutine may still touch scratch
	acquired bool // holds a sem token
	purge    bool // DELETE asked for scratch removal once execute exits
	purged   bool // scratch is gone; artefacts are not served
}

// runView is the immutable snapshot handlers read.
type runView struct {
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
	BytesIn    int64
	BytesOut   int64
	Purged     bool
}

func (r *runRec) view() runView {
	var exit *int
	if r.ExitCode != nil {
		e := *r.ExitCode
		exit = &e
	}
	return runView{
		ID: r.ID, Owner: r.Owner, Status: r.Status, ExitCode: exit, Reason: r.Reason,
		StartedAt: r.StartedAt, FinishedAt: r.FinishedAt, Req: r.Req, Scratch: r.Scratch,
		Logs: r.Logs, BytesIn: r.BytesIn, BytesOut: r.BytesOut, Purged: r.purged,
	}
}

// Options configures New.
type Options struct {
	Config *config.Config
	Docker runner.Runner
	Images *images.Map
	Store  *workspace.Store
	Audit  *audit.Logger
	Tier   rt.Selection
	// Owner prepares scratch for the sandbox uid; nil keeps private modes
	// (tests) and passes the default sandbox user to the runner.
	Owner *perm.Owner
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
		ws, err = workspace.NewWithOwner(opt.Config.WorkspacesDir, opt.Owner)
		if err != nil {
			return nil, err
		}
	}
	aud := opt.Audit
	if aud == nil {
		aud = audit.New(os.Stdout)
	}
	var d runner.Runner = opt.Docker
	if d == nil {
		d = &runner.Docker{}
	}
	if err := os.MkdirAll(opt.Config.ScratchDir, 0o750); err != nil {
		return nil, err
	}
	maxConc := opt.Config.MaxConcurrent
	if maxConc <= 0 {
		maxConc = 1
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
		owner:  opt.Owner,
		sem:    make(chan struct{}, maxConc),
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

func (s *Server) handleHealth(w http.ResponseWriter, _ *http.Request) {
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
			// Egress has no proxy in A0–A2; Validate refuses every allow-list.
			Egress: false,
		},
	})
}

func (s *Server) scratchFor(id string) string {
	return filepath.Join(s.cfg.ScratchDir, id)
}

func (s *Server) getView(id string) (runView, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	rec, ok := s.runs[id]
	if !ok {
		return runView{}, false
	}
	return rec.view(), true
}

// StartJanitor prunes finished runs older than RunRetention every interval
// until ctx is done.
func (s *Server) StartJanitor(ctx context.Context, interval time.Duration) {
	if interval <= 0 {
		interval = time.Minute
	}
	go func() {
		t := time.NewTicker(interval)
		defer t.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case now := <-t.C:
				s.Prune(now)
			}
		}
	}()
}

// Prune removes finished, inactive runs whose FinishedAt is older than the
// retention and deletes their scratch. It returns the number pruned.
func (s *Server) Prune(now time.Time) int {
	type victim struct {
		id      string
		scratch string
	}
	var victims []victim
	s.mu.Lock()
	for id, rec := range s.runs {
		if !rec.done || rec.active || rec.FinishedAt.IsZero() {
			continue
		}
		if now.Sub(rec.FinishedAt) < s.cfg.RunRetention {
			continue
		}
		victims = append(victims, victim{id: id, scratch: rec.Scratch})
		delete(s.runs, id)
	}
	s.mu.Unlock()
	for _, v := range victims {
		if v.scratch != "" {
			_ = os.RemoveAll(v.scratch)
		}
	}
	return len(victims)
}
