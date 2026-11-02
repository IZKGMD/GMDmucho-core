package admin

import (
	"testing"
	"time"
)

func TestRFC4226CodesAndTOTPWindow(t *testing.T) {
	const secret = "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"
	for counter, want := range []string{"755224", "287082", "359152", "969429", "338314", "254676", "287922", "162583", "399871", "520489"} {
		if got := TOTP(secret, int64(counter)); got != want {
			t.Fatalf("counter %d: %s", counter, got)
		}
	}
	now := time.Unix(3000, 0)
	for _, counter := range []int64{99, 100, 101} {
		if !VerifyTOTP(secret, TOTP(secret, counter), now) {
			t.Fatalf("window rejected %d", counter)
		}
	}
	if VerifyTOTP(secret, TOTP(secret, 98), now) || VerifyTOTP(secret, "12345", now) || VerifyTOTP("", "123456", now) {
		t.Fatal("invalid TOTP accepted")
	}
}
