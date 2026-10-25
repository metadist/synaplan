package store

import (
	"context"
	"errors"
	"time"

	"github.com/redis/go-redis/v9"
)

// Redis writes the live snapshot and the minute aggregates.
type Redis struct {
	client *redis.Client
}

// Open parses a redis URL. An empty URL still returns a store whose ping fails,
// so a server without Redis stays live and reports not ready.
func Open(url string) (Store, error) {
	if url == "" {
		return Unconfigured{}, nil
	}
	options, err := redis.ParseURL(url)
	if err != nil {
		return nil, err
	}
	return &Redis{client: redis.NewClient(options)}, nil
}

// Put sets one key with its TTL.
func (r *Redis) Put(ctx context.Context, key string, body []byte, ttl time.Duration) error {
	return r.client.Set(ctx, key, body, ttl).Err()
}

// Get returns nil, nil when the key does not exist.
func (r *Redis) Get(ctx context.Context, key string) ([]byte, error) {
	body, err := r.client.Get(ctx, key).Bytes()
	if errors.Is(err, redis.Nil) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return body, nil
}

// PutField sets one hash field and refreshes the hash TTL in one round trip.
func (r *Redis) PutField(ctx context.Context, key, field, value string, ttl time.Duration) error {
	pipe := r.client.Pipeline()
	pipe.HSet(ctx, key, field, value)
	pipe.Expire(ctx, key, ttl)
	_, err := pipe.Exec(ctx)
	return err
}

// Ping checks that Redis answers.
func (r *Redis) Ping(ctx context.Context) error {
	return r.client.Ping(ctx).Err()
}

// Unconfigured is the store used when no Redis URL is set.
type Unconfigured struct{}

// ErrUnconfigured is returned by ping when Redis was not configured.
var ErrUnconfigured = errors.New("redis is not configured")

// Put drops the write.
func (Unconfigured) Put(context.Context, string, []byte, time.Duration) error {
	return ErrUnconfigured
}

// Get reports that Redis is not configured.
func (Unconfigured) Get(context.Context, string) ([]byte, error) {
	return nil, ErrUnconfigured
}

// PutField drops the write.
func (Unconfigured) PutField(context.Context, string, string, string, time.Duration) error {
	return ErrUnconfigured
}

// Ping reports that Redis is not configured.
func (Unconfigured) Ping(context.Context) error {
	return ErrUnconfigured
}
