// Package oai is the spike's incremental OpenAI chat-completions decoder.
//
// It reads the same delta.content field the production provider reads.
// Feed may be split at any byte. Chunks are identical to a feed of the
// whole body. This is the G7 guard for one scripted answer, not the
// production provider.
package oai

import (
	"encoding/json"
	"strings"
)

// Decoder accumulates a partial SSE body.
type Decoder struct {
	buf string
}

type packet struct {
	Choices []struct {
		Delta struct {
			Content string `json:"content"`
		} `json:"delta"`
	} `json:"choices"`
}

// Feed returns the content deltas in this slice. A partial line stays buffered.
func (d *Decoder) Feed(b string) []string {
	d.buf += b
	var out []string
	for {
		i := strings.IndexByte(d.buf, '\n')
		if i < 0 {
			return out
		}
		line := strings.TrimRight(d.buf[:i], "\r")
		d.buf = d.buf[i+1:]
		if !strings.HasPrefix(line, "data: ") {
			continue
		}
		data := strings.TrimPrefix(line, "data: ")
		if data == "[DONE]" {
			continue
		}
		var pkt packet
		if json.Unmarshal([]byte(data), &pkt) != nil || len(pkt.Choices) == 0 {
			continue
		}
		if pkt.Choices[0].Delta.Content != "" {
			out = append(out, pkt.Choices[0].Delta.Content)
		}
	}
}

// Script writes one chat-completions stream for the given content pieces.
func Script(pieces []string) string {
	var b strings.Builder
	for _, piece := range pieces {
		raw, _ := json.Marshal(map[string]any{
			"choices": []any{map[string]any{"delta": map[string]any{"content": piece}}},
		})
		b.WriteString("data: ")
		b.Write(raw)
		b.WriteByte('\n')
	}
	b.WriteString("data: [DONE]\n")
	return b.String()
}
