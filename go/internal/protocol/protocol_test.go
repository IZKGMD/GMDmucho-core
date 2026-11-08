package protocol_test

import (
	"encoding/base64"
	"encoding/json"
	"os"
	"strings"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/discovery"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

func decode(t *testing.T, value string) string {
	t.Helper()
	b, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestGD22MessageWireCodec(t *testing.T) {
	subject := "MuchoCore: Subject #|~ Привет"
	body := "MuchoCore: Body #|~ -4 Привет 😀"

	subjectWire := protocol.EncodeMessageSubject(subject)
	bodyWire := protocol.EncodeMessageBody(body)

	if got, ok := protocol.DecodeMessageSubject(subjectWire); !ok || got != subject {
		t.Fatalf("subject round trip: got %q, ok=%v", got, ok)
	}
	if got, ok := protocol.DecodeMessageBody(bodyWire); !ok || got != body {
		t.Fatalf("body round trip: got %q, ok=%v", got, ok)
	}
	if _, ok := protocol.DecodeMessageSubject("%%%"); ok {
		t.Fatal("malformed subject Base64 was accepted")
	}
	if _, ok := protocol.DecodeMessageBody("%%%"); ok {
		t.Fatal("malformed body Base64 was accepted")
	}
	for _, wire := range []string{subjectWire, bodyWire} {
		if strings.ContainsAny(wire, ":|#~") {
			t.Fatalf("wire text contains a protocol delimiter: %q", wire)
		}
	}
}

func TestPHPParity(t *testing.T) {
	data, err := os.ReadFile("../../testdata/protocol.json")
	if err != nil {
		t.Fatal(err)
	}
	var fixtures struct {
		Paths                []struct{ Input, Output string }
		Hashes               []struct{ Input, Level, Metadata, Rewards string }
		Usernames, Passwords []struct{ Input, Output string }
		Songs                []struct {
			Size   float64
			Output string
		}
	}
	if err := json.Unmarshal(data, &fixtures); err != nil {
		t.Fatal(err)
	}
	for _, f := range fixtures.Paths {
		if got := discovery.NormalizePath(f.Input); got != f.Output {
			t.Errorf("path %q: got %q, want %q", f.Input, got, f.Output)
		}
	}
	for _, f := range fixtures.Hashes {
		input := decode(t, f.Input)
		if protocol.LevelHash(input) != f.Level || protocol.MetadataHash(input) != f.Metadata || protocol.RewardsHash(input) != f.Rewards {
			t.Errorf("hash mismatch for %d bytes", len(input))
		}
	}
	for _, f := range fixtures.Usernames {
		if got := protocol.Username(decode(t, f.Input)); got != decode(t, f.Output) {
			t.Errorf("username bytes: got %q, want %q", got, decode(t, f.Output))
		}
	}
	for _, f := range fixtures.Passwords {
		if got := protocol.CopyPassword(decode(t, f.Input)); got != f.Output {
			t.Errorf("copy password: got %q, want %q", got, f.Output)
		}
	}
	for _, f := range fixtures.Songs {
		song := protocol.Song{ID: 42, Name: " Test:|#~Song ", AuthorID: 7, Size: f.Size, DownloadURL: "https://example.com/song.mp3", VideoID: "abc", ChannelID: "channel"}
		if got := protocol.EncodeSong(song); got != f.Output {
			t.Errorf("song size %v: got %q, want %q", f.Size, got, f.Output)
		}
	}
}

func FuzzXORRoundTrip(f *testing.F) {
	f.Add("hello", "26364")
	f.Add("\x00\xff", "")
	f.Fuzz(func(t *testing.T, value, key string) {
		if got := protocol.XOR(protocol.XOR(value, key), key); got != value {
			t.Fatal("XOR failed round trip")
		}
	})
}
