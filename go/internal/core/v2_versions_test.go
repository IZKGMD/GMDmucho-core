package core

import (
	"encoding/json"
	"os"
	"testing"
)

func TestPHPVersionComparison(t *testing.T) {
	data, err := os.ReadFile("../../testdata/v2-versions.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases []struct {
		A, B   string
		Result int
	}
	if err = json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	for _, tc := range cases {
		if got := comparePHPVersion(tc.A, tc.B); got != tc.Result {
			t.Errorf("%q / %q: Go %d; PHP %d", tc.A, tc.B, got, tc.Result)
		}
	}
}
