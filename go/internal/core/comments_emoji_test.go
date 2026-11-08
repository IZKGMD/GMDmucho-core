package core

import (
	"encoding/base64"
	"testing"
)

func TestNormalizeCommentPreservesGD2209EmojiShortcodes(t *testing.T) {
	const plain = ":fire: :skull: :eyes:"
	wire := base64.RawURLEncoding.EncodeToString([]byte(plain))

	got, err := normalizeComment(wire, 22)
	if err != nil {
		t.Fatalf("normalizeComment returned error: %v", err)
	}
	if got != plain {
		t.Fatalf("emoji shortcodes changed: got %q, want %q", got, plain)
	}
}

func TestNormalizeCommentKeepsLegacyDelimiterSanitization(t *testing.T) {
	got, err := normalizeComment("legacy:comment|with~delimiters#and\x00nul", 19)
	if err != nil {
		t.Fatalf("normalizeComment returned error: %v", err)
	}
	const want = "legacycommentwithdelimitersandnul"
	if got != want {
		t.Fatalf("legacy sanitization changed: got %q, want %q", got, want)
	}
}
