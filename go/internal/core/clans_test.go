package core

import (
	"encoding/json"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
)

// Runs after the PHP lifecycle replay on its isolated, migrated database.
func testClanConcurrency(t *testing.T, h *Handler) {
	t.Helper()
	call := func(id int64, path string, fields url.Values) *httptest.ResponseRecorder {
		fields.Set("accountID", strconv.FormatInt(id, 10))
		fields.Set("gjp2", account.GJP2("test-password"))
		r := httptest.NewRequest("POST", "/api/clans/"+path, strings.NewReader(fields.Encode()))
		r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		return w
	}
	w := call(1, "create", url.Values{"clanName": {"Concurrent Clan"}, "clanTag": {"RACE"}, "clanMaxMembers": {"2"}})
	if w.Code != 200 {
		t.Fatalf("create: %s", w.Body)
	}
	var clanID int64
	if err := h.db.QueryRow(`SELECT clan_id FROM mucho_clans WHERE tag='RACE'`).Scan(&clanID); err != nil {
		t.Fatal(err)
	}
	defer h.db.Exec(`DELETE FROM mucho_clans WHERE clan_id=?`, clanID)
	var wg sync.WaitGroup
	var joined atomic.Int32
	for _, id := range []int64{2, 4, 5, 6} {
		wg.Go(func() {
			w := call(id, "join", url.Values{"clanID": {strconv.FormatInt(clanID, 10)}})
			if w.Code == 200 {
				joined.Add(1)
			} else if !strings.Contains(w.Body.String(), "Clan is full.") {
				t.Errorf("join: %s", w.Body)
			}
		})
	}
	wg.Wait()
	if joined.Load() != 1 {
		t.Fatalf("%d joins into one free slot", joined.Load())
	}
	var count int
	if err := h.db.QueryRow(`SELECT COUNT(*) FROM mucho_clan_members WHERE clan_id=?`, clanID).Scan(&count); err != nil || count != 2 {
		t.Fatalf("members %d: %v", count, err)
	}
	w = call(1, "quests/create", url.Values{"metric": {"stars"}, "target": {"100"}})
	if w.Code != 200 {
		t.Fatalf("quest: %s", w.Body)
	}
	var created struct {
		Data struct {
			ID int64 `json:"quest_id"`
		}
	}
	if err := json.Unmarshal(w.Body.Bytes(), &created); err != nil {
		t.Fatal(err)
	}
	var baseline int64
	if err := h.db.QueryRow(`SELECT stars FROM profiles WHERE account_id=1`).Scan(&baseline); err != nil {
		t.Fatal(err)
	}
	for range 10 {
		wg.Go(func() {
			fields := url.Values{"accountID": {"1"}, "gjp2": {account.GJP2("test-password")}, "stars": {strconv.FormatInt(baseline+100, 10)}}
			r := httptest.NewRequest("POST", "/updateGJUserScore.php", strings.NewReader(fields.Encode()))
			r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
			w := httptest.NewRecorder()
			h.ServeHTTP(w, r)
			if w.Code != 200 || w.Body.String() != "1" {
				t.Errorf("score: %d %s", w.Code, w.Body)
			}
		})
	}
	wg.Wait()
	var progress, contribution int64
	if err := h.db.QueryRow(`SELECT progress FROM mucho_clan_quests WHERE quest_id=?`, created.Data.ID).Scan(&progress); err != nil {
		t.Fatal(err)
	}
	if err := h.db.QueryRow(`SELECT contribution FROM mucho_clan_quest_members WHERE quest_id=? AND account_id=1`, created.Data.ID).Scan(&contribution); err != nil {
		t.Fatal(err)
	}
	if progress != 100 || contribution != 100 {
		t.Fatalf("duplicated quest gain: progress=%d contribution=%d", progress, contribution)
	}
}
