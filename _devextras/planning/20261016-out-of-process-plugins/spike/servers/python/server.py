# OP0 spike — not product code. Started by ../../run.sh (mcp==2.3.0).
from mcp.server.mcpserver import MCPServer
from mcp.types import ToolAnnotations

mcp = MCPServer("python-tools", version="1.0.0")


@mcp.tool(annotations=ToolAnnotations(readOnlyHint=True))
def word_stats(text: str) -> dict:
    """Count words, characters and sentences in a text."""
    words = text.split()
    sentences = [s for s in text.replace("!", ".").replace("?", ".").split(".") if s.strip()]
    return {"words": len(words), "characters": len(text), "sentences": len(sentences), "runtime": "python"}


if __name__ == "__main__":
    mcp.run(transport="streamable-http", host="0.0.0.0", port=8000, stateless_http=True)
