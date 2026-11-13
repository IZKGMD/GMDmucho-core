package protocol

import (
	"testing"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

func TestMessageAgeAtFixedClock(t *testing.T) {
	now := time.Unix(1700000000, 0).UTC()
	for _, tc := range []struct {
		name, date, want string
	}{
		{"same instant", "2023-11-14 22:13:20", "0 seconds"},
		{"two minutes", "2023-11-14 22:11:15", "2 minutes"},
		{"future timestamp", "2023-11-14 22:14:20", "0 seconds"},
		{"invalid timestamp", "invalid", "0 seconds"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if got := MessageAgeAt(tc.date, now); got != tc.want {
				t.Fatalf("MessageAgeAt(%q) = %q, want %q", tc.date, got, tc.want)
			}
		})
	}

	msg := dbutil.Row{
		"id": "1", "account_id": "1", "to_account_id": "2",
		"username": "Alpha", "user_id": "10",
		"subject": "SGk=", "is_read": "0",
		"created_at": "2023-11-14 22:13:20",
	}
	got := MessageAt(msg, false, now)
	if want := ":7:0 seconds"; len(got) < len(want) || got[len(got)-len(want):] != want {
		t.Fatalf("MessageAt timestamp = %q, want suffix %q", got, want)
	}
}
