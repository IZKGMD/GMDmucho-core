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

func TestEncodedSongDownloadURL(t *testing.T) {
	raw := "https://music.example.test/songs/music.mp3?download=1&v=2"
	encoded := "https%3A%2F%2Fmusic.example.test%2Fsongs%2Fmusic.mp3%3Fdownload%3D1%26v%3D2"
	for _, value := range []string{raw, encoded} {
		wire := protocol.EncodeSong(protocol.Song{ID: 12, Name: "A Very Long Custom Song Name",
			AuthorName: "Author", DownloadURL: value})
		if !strings.Contains(wire, "10~|~"+encoded+"~|~7") {
			t.Fatalf("custom song link not normalized: %q", wire)
		}
	}
	for _, value := range []string{"file:///etc/passwd", "https://user:pass@music.example.test/f.mp3", "javascript:alert(1)"} {
		wire := protocol.EncodeSong(protocol.Song{ID: 12, DownloadURL: value})
		if !strings.Contains(wire, "10~|~~|~7") {
			t.Errorf("unsafe download URL leaked into GD response: %q", wire)
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
