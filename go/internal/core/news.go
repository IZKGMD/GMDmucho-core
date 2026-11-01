package core

import (
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/base64"
	"fmt"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
)

var newsFilename = regexp.MustCompile(`^mcnews_[a-f0-9]{64}\.jpg$`)
var newsImagePath = regexp.MustCompile(`^/(?:gd-content/)?news/(mcnews_[a-f0-9]{64}\.jpg)$`)

func safeNewsLink(raw string) bool {
	u, err := url.Parse(raw)
	return err == nil && strings.EqualFold(u.Scheme, "https") && u.Hostname() != "" && u.User == nil && !regexp.MustCompile(`[\x00-\x20\x7f]`).MatchString(raw)
}
func youtubeURL(raw string) (string, error) {
	if raw == "" {
		return "", nil
	}
	if len(raw) > 512 || !safeNewsLink(raw) {
		return "", fmt.Errorf("invalid video URL")
	}
	u, _ := url.Parse(raw)
	if u.Port() != "" && u.Port() != "443" {
		return "", fmt.Errorf("invalid video port")
	}
	id := ""
	switch strings.ToLower(u.Hostname()) {
	case "youtu.be":
		id = strings.Trim(u.Path, "/")
	case "youtube.com", "www.youtube.com", "m.youtube.com", "music.youtube.com":
		if u.Path == "/watch" {
			id = u.Query().Get("v")
		} else {
			parts := strings.Split(strings.Trim(u.Path, "/"), "/")
			if len(parts) == 2 && (parts[0] == "shorts" || parts[0] == "embed" || parts[0] == "live") {
				id = parts[1]
			}
		}
	}
	if !regexp.MustCompile(`^[A-Za-z0-9_-]{11}$`).MatchString(id) {
		return "", fmt.Errorf("invalid video ID")
	}
	return "https://www.youtube.com/watch?v=" + id, nil
}
func (h *Handler) publishedNews(r request) ([]map[string]any, error) {
	rows, err := dbutil.TypedQuery(r.ctx, h.db, `SELECT id,title,body,external_url AS url,is_pinned AS pinned,published_at FROM mucho_news_posts WHERE is_published=1 ORDER BY is_pinned DESC,published_at DESC,id DESC LIMIT 30`)
	if err != nil {
		return nil, err
	}
	ids := make([]any, 0, len(rows))
	posts := map[int64]map[string]any{}
	for _, row := range rows {
		row["pinned"] = anyInt(row["pinned"]) == 1
		row["media"] = []map[string]any{}
		id := anyInt(row["id"])
		posts[id] = row
		ids = append(ids, id)
	}
	if len(ids) == 0 {
		return rows, nil
	}
	media, err := dbutil.TypedQuery(r.ctx, h.db, `SELECT id,post_id,kind,filename,width,height,target_url FROM mucho_news_media WHERE post_id IN (`+strings.TrimRight(strings.Repeat("?,", len(ids)), ",")+`) ORDER BY id`, ids...)
	if err != nil {
		return nil, err
	}
	for _, row := range media {
		post := posts[anyInt(row["post_id"])]
		delete(row, "post_id")
		row["url"] = "/news/image.php?file=" + row["filename"].(string)
		post["media"] = append(post["media"].([]map[string]any), row)
	}
	return rows, nil
}
func nativeNewsText(posts []map[string]any) (string, error) {
	enc := func(raw string) string { return base64.URLEncoding.EncodeToString([]byte(raw)) }
	rows := make([]string, 0, len(posts))
	for _, post := range posts[:min(30, len(posts))] {
		id := anyInt(post["id"])
		title, _ := post["title"].(string)
		body, _ := post["body"].(string)
		date, _ := post["published_at"].(string)
		media, _ := post["media"].([]map[string]any)
		published, err := time.ParseInLocation("2006-01-02 15:04:05", date, time.UTC)
		if err != nil || published.Unix() <= 0 || id < 1 || title == "" || (body == "" && len(media) == 0) {
			return "", fmt.Errorf("invalid published news")
		}
		elements := []string{}
		if body != "" {
			elements = append(elements, "0,"+enc(body))
		}
		link, _ := post["url"].(string)
		for _, item := range media {
			filename, _ := item["filename"].(string)
			kind, _ := item["kind"].(string)
			w, h := anyInt(item["width"]), anyInt(item["height"])
			if !newsFilename.MatchString(filename) || w < 1 || w > 1600 || h < 1 || h > 1600 || (kind != "image" && kind != "youtube") {
				return "", fmt.Errorf("invalid news media")
			}
			target, video := link, 0
			if kind == "youtube" {
				video = 1
				raw, _ := item["target_url"].(string)
				target, err = youtubeURL(raw)
				if err != nil || target == "" {
					return "", fmt.Errorf("invalid video link")
				}
			}
			if target != "" && !safeNewsLink(target) {
				return "", fmt.Errorf("invalid image link")
			}
			elements = append(elements, fmt.Sprintf("1,%s,%d,%d,%d,%s", filename, w, h, video, enc(target)))
		}
		if link != "" {
			if !safeNewsLink(link) {
				return "", fmt.Errorf("invalid news link")
			}
			elements = append(elements, "2,"+enc("Read more")+","+enc(link))
		}
		rows = append(rows, fmt.Sprintf("1:%d;2:%s;3:%s;4:%s", id, enc(published.Format("January 2, 2006")), enc(title), strings.Join(elements, "~")))
	}
	out := "4," + strconv.Itoa(len(rows))
	if len(rows) > 0 {
		out += "|" + strings.Join(rows, "|")
	}
	return out, nil
}
func (h *Handler) news(r request) reply {
	if r.raw.Method != "GET" {
		if r.path == "/muchocore/news" {
			return jsonReply(map[string]any{"error": "Method not allowed"}, 405)
		}
		return reply{Status: 405}
	}
	if strings.HasSuffix(r.path, "news_version.txt") {
		var version string
		if err := h.db.QueryRowContext(r.ctx, `SELECT version FROM mucho_news_state WHERE id=1`).Scan(&version); err != nil {
			return reply{Status: 503}
		}
		return reply{Body: version, Status: 200}
	}
	posts, err := h.publishedNews(r)
	if err != nil {
		if r.path == "/muchocore/news" {
			return jsonReply(map[string]any{"error": "News is unavailable."}, 503)
		}
		return reply{Status: 503}
	}
	if r.path == "/muchocore/news" {
		return jsonReply(map[string]any{"version": 1, "posts": posts}, 200)
	}
	value, err := nativeNewsText(posts)
	if err != nil {
		return reply{Status: 503}
	}
	var data bytes.Buffer
	writer, err := gzip.NewWriterLevel(&data, gzip.BestCompression)
	if err != nil {
		return reply{Status: 503}
	}
	if _, err = writer.Write([]byte(value)); err != nil {
		return reply{Status: 503}
	}
	if err = writer.Close(); err != nil {
		return reply{Status: 503}
	}
	return reply{Body: base64.URLEncoding.EncodeToString(data.Bytes()), Status: 200}
}
func (h *Handler) newsImage(r request) reply {
	headers := http.Header{"X-Content-Type-Options": {"nosniff"}, "Cache-Control": {"no-store"}}
	response := reply{Status: 404, Headers: headers}
	if r.raw.Method != "GET" && r.raw.Method != "HEAD" {
		headers.Set("Allow", "GET, HEAD")
		response.Status = 405
		return response
	}
	filename := r.raw.URL.Query().Get("file")
	if match := newsImagePath.FindStringSubmatch(r.path); len(match) == 2 {
		filename = match[1]
	}
	if !newsFilename.MatchString(filename) {
		return response
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT m.image_data FROM mucho_news_media m JOIN mucho_news_posts p ON p.id=m.post_id WHERE m.filename=? AND p.is_published=1 LIMIT 1`, filename)
	if err != nil {
		response.Status = 503
		return response
	}
	if len(rows) == 0 {
		return response
	}
	data := rows[0]["image_data"]
	etag := fmt.Sprintf(`"%x"`, sha256.Sum256([]byte(data)))
	headers.Set("Cache-Control", "public, max-age=0, must-revalidate")
	headers.Set("ETag", etag)
	headers.Set("Content-Disposition", `inline; filename="`+filename+`"`)
	response.ContentType = "image/jpeg"
	response.Status = 200
	if strings.TrimSpace(r.raw.Header.Get("If-None-Match")) == etag {
		response.Status = 304
		return response
	}
	headers.Set("Content-Length", strconv.Itoa(len(data)))
	if r.raw.Method != "HEAD" {
		response.Body = data
	}
	return response
}
