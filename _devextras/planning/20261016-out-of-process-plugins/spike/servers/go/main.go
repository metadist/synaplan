// OP0 spike — not product code. Started by ../../run.sh
// (github.com/modelcontextprotocol/go-sdk v1.8.0, Go 1.25).
package main

import (
	"context"
	"log"
	"net/http"

	"github.com/modelcontextprotocol/go-sdk/mcp"
)

type reverseInput struct {
	Text string `json:"text" jsonschema:"the text to reverse"`
}

type reverseOutput struct {
	Reversed string `json:"reversed"`
	Runtime  string `json:"runtime"`
}

func reverse(_ context.Context, _ *mcp.CallToolRequest, in reverseInput) (*mcp.CallToolResult, reverseOutput, error) {
	r := []rune(in.Text)
	for i, j := 0, len(r)-1; i < j; i, j = i+1, j-1 {
		r[i], r[j] = r[j], r[i]
	}
	return nil, reverseOutput{Reversed: string(r), Runtime: "go"}, nil
}

func main() {
	server := mcp.NewServer(&mcp.Implementation{Name: "go-tools", Version: "1.0.0"}, nil)
	mcp.AddTool(server, &mcp.Tool{
		Name:        "reverse_text",
		Description: "Reverse a text.",
		Annotations: &mcp.ToolAnnotations{ReadOnlyHint: true},
	}, reverse)

	handler := mcp.NewStreamableHTTPHandler(func(*http.Request) *mcp.Server { return server }, &mcp.StreamableHTTPOptions{Stateless: true})
	mux := http.NewServeMux()
	mux.Handle("/mcp", handler)
	log.Println("go-tools MCP on :8000/mcp")
	log.Fatal(http.ListenAndServe(":8000", mux))
}
