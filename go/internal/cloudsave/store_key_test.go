package cloudsave

import (
	"bytes"
	"encoding/base64"
	"encoding/hex"
	"testing"
)

func TestDecodeKeyAcceptsBase64AndLegacyHex(t *testing.T) {
	original := make([]byte, 32)
	for i := range original {
		original[i] = byte(i*7 + 3)
	}

	tests := []struct {
		name string
		raw  []byte
	}{
		{name: "base64", raw: []byte(base64.StdEncoding.EncodeToString(original) + "\n")},
		{name: "legacy hex", raw: []byte(hex.EncodeToString(original) + "\n")},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got, err := decodeKey(test.raw)
			if err != nil {
				t.Fatalf("decodeKey() error = %v", err)
			}
			if !bytes.Equal(got, original) {
				t.Fatal("decoded key differs from original 32 bytes")
			}
		})
	}
}

func TestDecodeKeyRejectsMalformedAndWrongLengthKeys(t *testing.T) {
	for _, raw := range [][]byte{
		[]byte("not-a-key"),
		[]byte("001122"),
		[]byte(base64.StdEncoding.EncodeToString(make([]byte, 31))),
	} {
		if _, err := decodeKey(raw); err == nil {
			t.Fatalf("decodeKey(%q) unexpectedly succeeded", raw)
		}
	}
}
