// Package store writes capacity documents. Tests use Memory.
package store

import (
	"context"
	"sync"
	"time"
)

// Store is the Redis contract the agent needs.
type Store interface {
	Put(ctx context.Context, key string, body []byte, ttl time.Duration) error
	Get(ctx context.Context, key string) ([]byte, error)
	// PutField sets one field of a hash and refreshes the hash's TTL.
	PutField(ctx context.Context, key, field, value string, ttl time.Duration) error
	Ping(ctx context.Context) error
}

// Memory is an in-process store. A missing key returns nil, nil.
type Memory struct {
	mu     sync.Mutex
	items  map[string]item
	hashes map[string]map[string]string
	ttls   map[string]time.Duration
	ping   error
}

type item struct {
	body []byte
	ttl  time.Duration
}

// NewMemory returns an empty store.
func NewMemory() *Memory {
	return &Memory{items: map[string]item{}, hashes: map[string]map[string]string{}, ttls: map[string]time.Duration{}}
}

// PutField stores one hash field.
func (m *Memory) PutField(_ context.Context, key, field, value string, ttl time.Duration) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.hashes[key] == nil {
		m.hashes[key] = map[string]string{}
	}
	m.hashes[key][field] = value
	m.ttls[key] = ttl
	return nil
}

// Field returns one hash field, or "" when absent.
func (m *Memory) Field(key, field string) string {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.hashes[key][field]
}

// HashTTL reports the TTL last given to a hash.
func (m *Memory) HashTTL(key string) time.Duration {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.ttls[key]
}

// SetPing controls the next readiness check.
func (m *Memory) SetPing(err error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.ping = err
}

// Put stores one document and the TTL it was given.
func (m *Memory) Put(_ context.Context, key string, body []byte, ttl time.Duration) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	copied := append([]byte(nil), body...)
	m.items[key] = item{body: copied, ttl: ttl}
	return nil
}

// Get returns a copy, or nil when the key is absent.
func (m *Memory) Get(_ context.Context, key string) ([]byte, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	item, ok := m.items[key]
	if !ok {
		return nil, nil
	}
	return append([]byte(nil), item.body...), nil
}

// TTL reports the TTL of a stored key.
func (m *Memory) TTL(key string) time.Duration {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.items[key].ttl
}

// Ping reports the injected error.
func (m *Memory) Ping(context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.ping
}
