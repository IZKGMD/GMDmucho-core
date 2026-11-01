package discovery

import (
	"context"
	"database/sql"
	"errors"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

type fakeStore struct {
	err    error
	id     int64
	offset int
	kind   string
}

func (s *fakeStore) Ping(context.Context) error { return s.err }
func (s *fakeStore) Song(_ context.Context, id int64) (protocol.Song, error) {
	s.id = id
	return protocol.Song{ID: id}, s.err
}
func (s *fakeStore) Gauntlets(context.Context) ([]protocol.Gauntlet, error) { return nil, s.err }
func (s *fakeStore) MapPacks(_ context.Context, offset int) ([]protocol.MapPack, int, error) {
	s.offset = offset
	return nil, 0, s.err
}
func (s *fakeStore) Daily(_ context.Context, kind string) (int64, int64, error) {
	s.kind = kind
	return 42, 3600, s.err
}

func request(h http.Handler, method, path, body string) *httptest.ResponseRecorder {
	r := httptest.NewRequest(method, path, strings.NewReader(body))
	r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	return w
}

func TestHTTPCompatibility(t *testing.T) {
	for _, tc := range []struct{ path, body, expected string }{
		{"/health", "", "1"}, {"/a/checkIfServerOnline.php", "", "1"},
		{"/unsupported.php", "", "-1"}, {"/accounts/loginGJAccount22.php", "", "-1"},
		{"/getGJSongInfo.php", "songID=bad", "-1"},
		{"/getGJSongInfo.php?songID=1", "", "-1"},
		{"/getGJDailyLevel.php", "", "42|3600"},
		{"/a/database/getGJDailyLevel.php", "type=1", "100043|3600"},
		{"/getGJDailyLevel.php", "type=2", "200043|3600"},
		{"/getGJDailyLevel.php", "weekly=1", "100043|3600"},
		{"/getGJDailyLevel.php", "type=0&weekly=1", "42|3600"},
		{"/getGJDailyLevel.php", "type=3", "-1"},
	} {
		t.Run(tc.path+"/"+tc.body, func(t *testing.T) {
			w := request(Handler{Store: &fakeStore{}}, "POST", tc.path, tc.body)
			if w.Code != 200 || w.Body.String() != tc.expected {
				t.Fatalf("got %d %q, want 200 %q", w.Code, w.Body.String(), tc.expected)
			}
			if w.Header().Get("Content-Type") != "text/plain; charset=utf-8" {
				t.Fatal("incorrect content type")
			}
		})
	}
}

func TestInputBoundaries(t *testing.T) {
	for _, tc := range []struct {
		body string
		id   int64
	}{
		{"songID=1&songID=42", 42}, {"songID=%2B42", 0}, {"songID=42junk", 0},
		{"songID=42%0A", 42}, {"songID[]=42", 0}, {"songID=-1", 0},
		{"songID=9223372036854775808", 9223372036854775807},
	} {
		s := &fakeStore{}
		request(Handler{Store: s}, "POST", "/getGJSongInfo.php", tc.body)
		if s.id != tc.id {
			t.Errorf("%q: id=%d, want %d", tc.body, s.id, tc.id)
		}
	}
	for _, tc := range []struct {
		body   string
		offset int
	}{
		{"page=-1", 0}, {"page=1001", 10000}, {"page=2junk", 20}, {"page=9223372036854775808", 10000},
	} {
		s := &fakeStore{}
		request(Handler{Store: s}, "POST", "/getGJMapPacks20.php", tc.body)
		if s.offset != tc.offset {
			t.Errorf("%q: offset=%d, want %d", tc.body, s.offset, tc.offset)
		}
	}
	for _, method := range []string{"GET", "PUT", "PATCH"} {
		s := &fakeStore{}
		request(Handler{Store: s}, method, "/getGJSongInfo.php?songID=42", "songID=42")
		if s.id != 0 {
			t.Fatalf("accepted non-POST input for %s", method)
		}
	}
}

func TestDatabaseFailures(t *testing.T) {
	for _, err := range []error{sql.ErrNoRows, errors.New("private database details")} {
		h := Handler{Store: &fakeStore{err: err}}
		for _, path := range []string{"/getGJSongInfo.php", "/getGJGauntlets21.php", "/getGJMapPacks21.php", "/getGJDailyLevel.php"} {
			w := request(h, "POST", path, "songID=42")
			if w.Code != 200 || w.Body.String() != "-1" {
				t.Fatalf("%s leaked failure: %d %s", path, w.Code, w.Body.String())
			}
		}
		if w := request(h, "GET", "/ready", ""); w.Code != 503 || w.Body.String() != "-1" {
			t.Fatal("readiness did not fail")
		}
		if w := request(h, "GET", "/health", ""); w.Code != 200 || w.Body.String() != "1" {
			t.Fatal("liveness depended on database")
		}
	}
}

func TestBodyLimitAndMalformedForms(t *testing.T) {
	h := Handler{Store: &fakeStore{}}
	for _, tc := range []struct {
		body   string
		status int
	}{
		{"songID=" + strings.Repeat("1", MaxBodyBytes), 413}, {"songID=%zz", 400},
	} {
		w := request(h, "POST", "/getGJSongInfo.php", tc.body)
		if w.Code != tc.status || w.Body.String() != "-1" {
			t.Fatalf("got %d %q, want %d -1", w.Code, w.Body.String(), tc.status)
		}
	}
}

func TestRealHTTPDoesNotRedirectPOST(t *testing.T) {
	server := httptest.NewServer(Handler{Store: &fakeStore{}})
	defer server.Close()
	client := server.Client()
	client.CheckRedirect = func(*http.Request, []*http.Request) error { return errors.New("unexpected redirect") }
	response, err := client.Post(server.URL+"/a//database/getGJDailyLevel.php/", "application/x-www-form-urlencoded", strings.NewReader("type=2"))
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	body, err := io.ReadAll(response.Body)
	if err != nil || string(body) != "200043|3600" {
		t.Fatalf("unexpected response %q: %v", body, err)
	}
}

func TestMultipartForm(t *testing.T) {
	var body strings.Builder
	writer := multipart.NewWriter(&body)
	if err := writer.WriteField("songID", "42"); err != nil {
		t.Fatal(err)
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	r := httptest.NewRequest("POST", "/database/getGJSongInfo.php", strings.NewReader(body.String()))
	r.Header.Set("Content-Type", writer.FormDataContentType())
	s := &fakeStore{}
	w := httptest.NewRecorder()
	Handler{Store: s}.ServeHTTP(w, r)
	if w.Code != 200 || s.id != 42 {
		t.Fatalf("multipart: status=%d, songID=%d", w.Code, s.id)
	}
}

func FuzzNormalizePath(f *testing.F) {
	f.Add("/a/database/getGJGauntlets20.php")
	f.Fuzz(func(t *testing.T, path string) {
		got := NormalizePath(path)
		if !strings.HasPrefix(got, "/") {
			t.Fatal("non-absolute normalized path")
		}
	})
}
