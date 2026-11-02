package protocol

import (
	"net/url"
	"regexp"
	"strconv"
	"strings"
)

type Version struct{ Game, Binary int }

var legacyComments = regexp.MustCompile(`(?i)/(getgjcomments(19)?|uploadgjcomment(19)?|deletegjcomment(19)?|updategjlevel(19)?)(\.php)?$`)
var legacyGame = regexp.MustCompile(`(?i)/(getgjlevels|uploadgjlevel|updategjlevel|downloadgjlevel|likegjlevel|rategjlevel|updategjusername|updategjuserscore)(\.php)?$`)
var modernComments = regexp.MustCompile(`(?i)/(getgjcomments21|uploadgjcomment21)(\.php)?$`)
var comments20 = regexp.MustCompile(`(?i)/(getgjcomments20|uploadgjcomment20|getgjaccountcomments20|uploadgjacccomment20)(\.php)?$`)

func VersionFrom(path string, form url.Values) Version {
	g, _ := strconv.Atoi(form.Get("gameVersion"))
	b, _ := strconv.Atoi(form.Get("binaryVersion"))
	if g == 0 && legacyComments.MatchString(path) {
		g = 1
		if strings.Contains(strings.ToLower(path), "19") {
			g = 19
		}
	}
	if g == 0 && legacyGame.MatchString(path) {
		g = 1
	}
	if g == 0 && modernComments.MatchString(path) {
		g = 21
		if b >= 51 {
			g = 22
		}
	}
	if g == 0 && comments20.MatchString(path) {
		g = 20
		if b >= 51 {
			g = 22
		}
	}
	return Version{max(0, g), max(0, b)}
}
func (v Version) Effective() int {
	if v.Game == 20 && v.Binary > 27 {
		return 21
	}
	return v.Game
}
func (v Version) Credential(form url.Values) string {
	keys := []string{"gjp", "gjp2"}
	if v.Effective() >= 22 {
		keys = []string{"gjp2", "gjp"}
	}
	for _, key := range keys {
		if val := form.Get(key); val != "" && val != "0" {
			return val
		}
	}
	return ""
}
