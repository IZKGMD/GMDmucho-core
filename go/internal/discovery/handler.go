// Package discovery serves the first native MuchoCore read-only endpoints.
package discovery

import (
	"context"
	"crypto/sha1"
	"database/sql"
	"encoding/base64"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

const MaxBodyBytes = 1 << 20

var slashes = regexp.MustCompile(`/+`)
var eventChallenge = regexp.MustCompile(`^[0-9]+$`)
var strictInteger = regexp.MustCompile(`^-?[0-9]+\n?$`)

// NormalizePath follows the PHP Router without redirecting GD POST requests.
func NormalizePath(path string) string {
	path = strings.SplitN(strings.SplitN(path, "?", 2)[0], "#", 2)[0]
	path = slashes.ReplaceAllString(path, "/")
	if !strings.HasPrefix(path, "/") {
		path = "/" + path
	}
	for {
		part := strings.SplitN(path[1:], "/", 2)[0]
		switch strings.ToLower(part) {
		case "database", "accounts", "api", "a":
			path = strings.TrimPrefix(path, "/"+part)
			if path == "" {
				path = "/"
			}
		default:
			goto stripped
		}
	}
stripped:
	path = strings.TrimRight(path, "/")
	path = strings.ToLower(path)
	path = strings.TrimSuffix(path, ".php")
	if path == "" {
		path = "/"
	}
	if alias, ok := aliases[path]; ok {
		return alias
	}
	return path
}

type Handler struct {
	Store  Store
	Logger *slog.Logger
}

func (h Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	defer func() {
		if recover() != nil {
			h.logFailure("panic")
			w.WriteHeader(http.StatusInternalServerError)
			_, _ = fmt.Fprint(w, "-1")
		}
	}()
	path := NormalizePath(r.URL.EscapedPath())
	if path == "/health" || path == "/checkifserveronline" {
		_, _ = fmt.Fprint(w, "1")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
	defer cancel()
	if path == "/ready" {
		if err := h.Store.Ping(ctx); err != nil {
			w.WriteHeader(http.StatusServiceUnavailable)
			_, _ = fmt.Fprint(w, "-1")
			return
		}
		_, _ = fmt.Fprint(w, "1")
		return
	}
	switch path {
	case "/getgjsonginfo", "/getgjgauntlets", "/getgjgauntlets21", "/getgjmappacks", "/getgjmappacks21", "/getgjdailylevel":
	default:
		_, _ = fmt.Fprint(w, "-1")
		return
	}
	// PHP's $_POST never includes query parameters or PUT/PATCH bodies.
	form := url.Values{}
	if r.Method == http.MethodPost {
		r.Body = http.MaxBytesReader(w, r.Body, MaxBodyBytes)
		err := r.ParseForm()
		if err == nil && strings.HasPrefix(strings.ToLower(r.Header.Get("Content-Type")), "multipart/form-data") {
			err = r.ParseMultipartForm(MaxBodyBytes)
		}
		if r.MultipartForm != nil {
			defer r.MultipartForm.RemoveAll()
		}
		if err != nil {
			var tooLarge *http.MaxBytesError
			if errors.As(err, &tooLarge) {
				w.WriteHeader(http.StatusRequestEntityTooLarge)
			} else {
				w.WriteHeader(http.StatusBadRequest)
			}
			_, _ = fmt.Fprint(w, "-1")
			return
		}
		form = r.PostForm
	}
	body, err := h.dispatch(ctx, path, form)
	if err != nil {
		if !errors.Is(err, sql.ErrNoRows) {
			h.logFailure(path)
		}
		body = "-1"
	}
	_, _ = fmt.Fprint(w, body)
}

func (h Handler) logFailure(operation string) {
	if h.Logger != nil {
		h.Logger.Error("native request failed", "operation", operation)
	}
}

// PHP retains the last scalar value for duplicate form keys.
func last(form url.Values, key string) string {
	values := form[key]
	if len(values) == 0 {
		return ""
	}
	return values[len(values)-1]
}

func integer(form url.Values, key string, fallback int64) int64 {
	value := last(form, key)
	if _, present := form[key]; !present {
		return fallback
	}
	if !strictInteger.MatchString(value) {
		return -1
	}
	n, err := strconv.ParseInt(strings.TrimSuffix(value, "\n"), 10, 64)
	if err != nil {
		return -1
	}
	return n
}

func (h Handler) dispatch(ctx context.Context, path string, form url.Values) (string, error) {
	switch path {
	case "/getgjsonginfo":
		id := integer(form, "songID", 0)
		if id <= 0 {
			return "-1", nil
		}
		song, err := h.Store.Song(ctx, id)
		if err != nil {
			return "", err
		}
		return protocol.EncodeSong(song), nil
	case "/getgjgauntlets", "/getgjgauntlets21":
		rows, err := h.Store.Gauntlets(ctx)
		return protocol.EncodeGauntlets(rows), err
	case "/getgjmappacks", "/getgjmappacks21":
		page := integer(form, "page", 0)
		if page < 0 {
			return "-1", nil
		}
		page = min(1000, page)
		offset := int(page) * 10
		rows, total, err := h.Store.MapPacks(ctx, offset)
		return protocol.EncodeMapPacks(rows, total, offset), err
	case "/getgjdailylevel":
		field, maximum := "weekly", int64(1)
		if _, ok := form["type"]; ok {
			field, maximum = "type", 2
		}
		typ := integer(form, field, 0)
		if typ < 0 || typ > maximum {
			return "-1", nil
		}
		kind := []string{"daily", "weekly", "event"}[typ]
		id, remaining, err := h.Store.Daily(ctx, kind)
		if err != nil {
			return "", err
		}
		if typ == 1 {
			id += 100001
		} else if typ == 2 {
			id += 200001
			return eventReward(id, last(form, "chk")), nil
		}
		return fmt.Sprintf("%d|%d", id, max(0, remaining)), nil
	}
	return "-1", nil
}

func eventReward(id int64, chk string) string {
	if len(chk) <= 5 || len(chk) > 32 {
		return "-1"
	}
	const key = "59182"
	value := strings.NewReplacer("-", "+", "_", "/").Replace(chk[5:])
	value += strings.Repeat("=", (4-len(value)%4)%4)
	raw, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		return "-1"
	}
	challenge := protocol.XOR(string(raw), key)
	if !eventChallenge.MatchString(challenge) {
		return "-1"
	}
	plain := fmt.Sprintf("1:%s:%d:9:7,100,8,10", challenge, id)
	payload := base64.URLEncoding.EncodeToString([]byte(protocol.XOR(plain, key)))
	return fmt.Sprintf("%d|10|SaKuJ%s|%x", id, payload, sha1.Sum([]byte(payload+"pC26fpYaQCtg")))
}
