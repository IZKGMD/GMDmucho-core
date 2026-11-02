package main

import (
	"strings"
	"testing"
)

func TestAdministratorPasswordInput(t *testing.T) {
	for _, ending := range []string{"", "\n", "\r\n"} {
		actual, err := decodeAdminPassword(strings.NewReader(" password with spaces " + ending))
		if err != nil || actual != " password with spaces " {
			t.Fatalf("password whitespace changed: %v", err)
		}
	}
	for _, invalid := range []string{"", "too-short", "password\nsecond-line", strings.Repeat("p", 73), strings.Repeat("p", 10000), "long-password\x00"} {
		if _, err := decodeAdminPassword(strings.NewReader(invalid)); err == nil {
			t.Fatal("invalid or oversized password accepted")
		}
	}
}
