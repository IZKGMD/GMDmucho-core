package media

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"image"
	"os"
	"testing"
)

func TestPHPProfileRendering(t *testing.T) {
	path := os.Getenv("MUCHO_GO_IMAGE_FIXTURES")
	if path == "" {
		path = "../../testdata/profile-images.json"
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		Kind, Encoded, JPEG, Error string
		X, Y, Zoom, Rotation       int
	}
	if err = json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	if len(cases) < 32 {
		t.Fatal("incomplete image references")
	}
	for _, tc := range cases {
		t.Run(tc.Kind+"/"+tc.Error, func(t *testing.T) {
			got, err := RenderProfile(tc.Kind, tc.Encoded, tc.X, tc.Y, tc.Zoom, tc.Rotation)
			if tc.Error != "" {
				if err == nil || err.Error() != tc.Error {
					t.Fatalf("Go %v; PHP %s", err, tc.Error)
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if bytes.Contains(got, []byte("private-source-metadata")) {
				t.Fatal("source metadata leaked")
			}
			actual, _, err := image.Decode(bytes.NewReader(got))
			if err != nil {
				t.Fatal(err)
			}
			reference, err := base64.StdEncoding.DecodeString(tc.JPEG)
			if err != nil {
				t.Fatal(err)
			}
			expected, _, err := image.Decode(bytes.NewReader(reference))
			if err != nil {
				t.Fatal(err)
			}
			if actual.Bounds() != expected.Bounds() {
				t.Fatalf("dimensions %v; PHP %v", actual.Bounds(), expected.Bounds())
			}
			// Go and GD use different JPEG encoders/resamplers. Compare decoded
			// geometry and pixels, allowing bounded interpolation/compression noise.
			var difference, total uint64
			for y := actual.Bounds().Min.Y; y < actual.Bounds().Max.Y; y += 4 {
				for x := actual.Bounds().Min.X; x < actual.Bounds().Max.X; x += 4 {
					ar, ag, ab, _ := actual.At(x, y).RGBA()
					er, eg, eb, _ := expected.At(x, y).RGBA()
					for _, pair := range [][2]uint32{{ar, er}, {ag, eg}, {ab, eb}} {
						a, b := int64(pair[0]>>8), int64(pair[1]>>8)
						difference += uint64(max(a-b, b-a))
						total++
					}
				}
			}
			if float64(difference)/float64(total) > 12 {
				t.Fatalf("crop pixel difference %.2f", float64(difference)/float64(total))
			}
		})
	}
}
