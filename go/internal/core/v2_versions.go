package core

import (
	"strconv"
	"strings"
)

func versionDigit(c byte) bool { return c >= '0' && c <= '9' }
func versionCanonical(value string) string {
	if i := strings.IndexByte(value, 0); i >= 0 {
		value = value[:i]
	}
	if value == "" || value[0] == '#' {
		return value
	}
	out := make([]byte, 0, len(value)*2)
	out = append(out, value[0])
	previous := value[0]
	for i := 1; i < len(value); i++ {
		c := value[i]
		last := out[len(out)-1]
		switch {
		case c == '-' || c == '_' || c == '+':
			if last != '.' {
				out = append(out, '.')
			}
		case (!versionDigit(previous) && previous != '.' && versionDigit(c)) || (versionDigit(previous) && !versionDigit(c) && c != '.'):
			if last != '.' {
				out = append(out, '.')
			}
			out = append(out, c)
		case !(versionDigit(c) || (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')):
			if last != '.' {
				out = append(out, '.')
			}
		default:
			out = append(out, c)
		}
		previous = c
	}
	return string(out)
}
func versionSpecial(value string) int {
	for _, form := range []struct {
		name  string
		order int
	}{{"dev", 0}, {"alpha", 1}, {"a", 1}, {"beta", 2}, {"b", 2}, {"RC", 3}, {"rc", 3}, {"#", 4}, {"pl", 5}, {"p", 5}} {
		if strings.HasPrefix(value, form.name) {
			return form.order
		}
	}
	return -1
}
func comparePHPVersion(a, b string) int {
	if i := strings.IndexByte(a, 0); i >= 0 {
		a = a[:i]
	}
	if i := strings.IndexByte(b, 0); i >= 0 {
		b = b[:i]
	}
	sign := func(value int64) int {
		if value < 0 {
			return -1
		}
		if value > 0 {
			return 1
		}
		return 0
	}
	if a == "" || b == "" {
		if a == b {
			return 0
		}
		if a == "" {
			return -1
		}
		return 1
	}
	a, b = versionCanonical(a), versionCanonical(b)
	moreA, moreB := true, true
	for a != "" && b != "" && moreA && moreB {
		partA, restA, hasA := strings.Cut(a, ".")
		partB, restB, hasB := strings.Cut(b, ".")
		moreA, moreB = hasA, hasB
		numericA, numericB := len(partA) > 0 && versionDigit(partA[0]), len(partB) > 0 && versionDigit(partB[0])
		comparison := 0
		if numericA && numericB {
			va, _ := strconv.ParseInt(partA, 10, 64)
			vb, _ := strconv.ParseInt(partB, 10, 64)
			comparison = sign(va - vb)
		} else {
			if numericA {
				partA = "#N#"
			}
			if numericB {
				partB = "#N#"
			}
			comparison = sign(int64(versionSpecial(partA) - versionSpecial(partB)))
		}
		if comparison != 0 {
			return comparison
		}
		if hasA {
			a = restA
		}
		if hasB {
			b = restB
		}
	}
	if moreA {
		if a != "" && versionDigit(a[0]) {
			return 1
		}
		return comparePHPVersion(a, "#N#")
	}
	if moreB {
		if b != "" && versionDigit(b[0]) {
			return -1
		}
		return comparePHPVersion("#N#", b)
	}
	return 0
}
