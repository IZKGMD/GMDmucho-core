// Package webassets embeds the PHP-free player account page assets.
package webassets

import (
	"bytes"
	"crypto/sha256"
	"embed"
	"fmt"
	"io/fs"
	"net/http"
	"strings"
	"time"
)

//go:embed assets/* recovery/* muchocore-theme.css muchocore-theme.js
var files embed.FS

func Serve(w http.ResponseWriter, r *http.Request) bool {
	name := strings.TrimPrefix(r.URL.Path, "/")
	info, err := fs.Stat(files, name)
	if err != nil || info.IsDir() {
		return false
	}
	if r.Method != "GET" && r.Method != "HEAD" {
		w.Header().Set("Allow", "GET, HEAD")
		w.WriteHeader(405)
		return true
	}
	data, err := files.ReadFile(name)
	if err != nil {
		w.WriteHeader(503)
		return true
	}
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Cache-Control", "public, max-age=3600")
	w.Header().Set("ETag", fmt.Sprintf(`"%x"`, sha256.Sum256(data)))
	w.Header().Del("Content-Type")
	http.ServeContent(w, r, name, time.Time{}, bytes.NewReader(data))
	return true
}
