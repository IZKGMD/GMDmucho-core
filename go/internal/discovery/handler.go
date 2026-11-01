// Package discovery serves the first native MuchoCore read-only endpoints.
package discovery

import (
	"context"
	"database/sql"
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
var strictInteger = regexp.MustCompile(`^-?[0-9]+\n?$`)
var castInteger = regexp.MustCompile(`^[\t\n\r\v\f ]*[+-]?[0-9]+`)

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

func integer(form url.Values, key string, strict bool) int64 {
	value := last(form, key)
	if strict {
		if !strictInteger.MatchString(value) {
			return 0
		}
		value = strings.TrimSuffix(value, "\n")
	} else {
		value = strings.TrimSpace(castInteger.FindString(value))
	}
	n, _ := strconv.ParseInt(value, 10, 64) // overflow saturates, as PHP does
	return n
}

func (h Handler) dispatch(ctx context.Context, path string, form url.Values) (string, error) {
	switch path {
	case "/getgjsonginfo":
		id := integer(form, "songID", true)
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
		page := max(0, min(1000, integer(form, "page", false)))
		offset := int(page) * 10
		rows, total, err := h.Store.MapPacks(ctx, offset)
		return protocol.EncodeMapPacks(rows, total, offset), err
	case "/getgjdailylevel":
		typ := integer(form, "type", false)
		if _, ok := form["type"]; !ok && integer(form, "weekly", false) == 1 {
			typ = 1
		}
		if typ < 0 || typ > 2 {
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
		}
		return fmt.Sprintf("%d|%d", id, max(0, remaining)), nil
	}
	return "-1", nil
}
