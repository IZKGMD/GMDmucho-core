package media

import (
	"bytes"
	"os"
	"testing"
)

func TestMP3SignaturesAndTruncatedFrames(t *testing.T) {
	data, err := os.ReadFile("../../testdata/silence.mp3")
	if err != nil {
		t.Fatal(err)
	}
	if !MP3(bytes.NewReader(data), int64(len(data))) {
		t.Fatal("valid generated MP3 rejected")
	}
	for _, bad := range [][]byte{nil, []byte("ID3not-an-mp3-file"), []byte("RIFFxxxxWAVEanything"), []byte("OggSnot-mpeg"), data[:min(30, len(data))]} {
		if MP3(bytes.NewReader(bad), int64(len(bad))) {
			t.Fatal("non-MP3 or incomplete stream accepted")
		}
	}
}
