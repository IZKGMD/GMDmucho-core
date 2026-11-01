// Package core composes the native MuchoCore modules into one HTTP runtime.
package core

import (
	"context"
	"crypto/rand"
	"database/sql"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"net/netip"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/IZKGMD/GMDmucho-core/go/internal/account"
	"github.com/IZKGMD/GMDmucho-core/go/internal/cloudsave"
	"github.com/IZKGMD/GMDmucho-core/go/internal/discovery"
	"github.com/IZKGMD/GMDmucho-core/go/internal/integration"
	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
	"github.com/IZKGMD/GMDmucho-core/go/internal/security"
	"github.com/IZKGMD/GMDmucho-core/go/internal/webassets"
)

type Options struct {
	Logger                               *slog.Logger
	AccountURL, ContentURL, CloudKeyFile string
	DownloadKey                          string
	DisableProtect                       bool
	TrustedProxies                       []netip.Prefix
	Now                                  func() time.Time
	QuarantineScores                     bool
	Webhooks                             integration.Webhooks
	DownloadWindow                       int
	SecureCookies                        bool
	AssetBase, RecoveryPath              string
	PublicURL                            string
	RecoveryMailer                       integration.RecoveryMailer
	Turnstile                            security.Turnstile
}
type Handler struct {
	db          *sql.DB
	options     Options
	accounts    account.Service
	cloud       cloudsave.Store
	protect     security.Protect
	discovery   http.Handler
	routes      map[string]endpoint
	webRoutes   map[string]webEndpoint
	downloadKey []byte
	now         func() time.Time
}
type request struct {
	ctx      context.Context
	form     url.Values
	path, ip string
	version  protocol.Version
	raw      *http.Request
}
type endpoint func(request) (string, error)

var strictInt = regexp.MustCompile(`^-?[0-9]+\n?$`)

func (r request) str(key string) string {
	v := r.form[key]
	if len(v) == 0 {
		return ""
	}
	return v[len(v)-1]
}
func (r request) int(key string) int64 {
	v := r.str(key)
	if !strictInt.MatchString(v) {
		return 0
	}
	n, _ := strconv.ParseInt(strings.TrimSuffix(v, "\n"), 10, 64)
	return n
}
func (r request) page() int          { return int(max(0, min(1000, r.int("page")))) }
func (r request) credential() string { return r.version.Credential(r.form) }
func (h *Handler) auth(r request) (account.Identity, error) {
	return h.accounts.Authenticate(r.ctx, r.int("accountID"), r.credential())
}
func New(db *sql.DB, options Options) *Handler {
	h := &Handler{db: db, options: options, accounts: account.Service{DB: db}, cloud: cloudsave.Store{DB: db, KeyFile: options.CloudKeyFile}, protect: security.Protect{DB: db, Policies: security.Policies()}, discovery: discovery.Handler{Store: discovery.SQLStore{DB: db}, Logger: options.Logger}}
	h.now = options.Now
	if h.now == nil {
		h.now = time.Now
	}
	if h.options.DownloadWindow == 0 {
		h.options.DownloadWindow = 300
	}
	h.options.DownloadWindow = max(30, min(604800, h.options.DownloadWindow))
	h.routes = map[string]endpoint{
		"/getgjeditorsounds":     h.editorSounds,
		"/uploadgjeditorsound":   h.editorSounds,
		"/downloadgjeditorsound": h.editorSounds,
		"/deletegjeditorsound":   h.editorSounds,
		"/updategjsounddesc":     h.editorSounds,
		"/getgjdeathdata":        h.levelTelemetry,
		"/uploadgjdeathdata":     h.levelTelemetry,
		"/getgjlevelcontrols":    h.levelTelemetry,
		"/uploadgjlevelcontrols": h.levelTelemetry,
		"/deletegjlevelcontrols": h.levelTelemetry,

		"/getgjthreads":           h.threads,
		"/getgjthreadinvites":     h.threads,
		"/getgjthreadmessages":    h.threads,
		"/getgjthreadmembers":     h.threads,
		"/sendgjthreadmessage":    h.threads,
		"/searchgjthreadmessages": h.threads,
		"/updategjthread":         h.threads,

		"/admincommentban":           h.sanction,
		"/admincommentunban":         h.sanction,
		"/adminuploadban":            h.sanction,
		"/adminuploadunban":          h.sanction,
		"/adminmessageban":           h.sanction,
		"/adminmessageunban":         h.sanction,
		"/adminfreezeaccount":        h.sanction,
		"/adminunfreezeaccount":      h.sanction,
		"/admintrendingban":          h.sanction,
		"/admintrendingunban":        h.sanction,
		"/adminnamelock":             h.sanction,
		"/adminnameunlock":           h.sanction,
		"/adminqueuemute":            h.sanction,
		"/adminqueueunmute":          h.sanction,
		"/reportgjcontent":           h.moderationCase,
		"/admingetreportqueue":       h.moderationCase,
		"/admingetuserhistory":       h.moderationCase,
		"/admingetreportedcontent":   h.moderationCase,
		"/admingetabuseinfo":         h.moderationCase,
		"/admingetaccountreview":     h.moderationCase,
		"/adminclearreportedcontent": h.moderationCase,
		"/adminclosecase":            h.moderationCase,
		"/deletegjcomments":          h.moderateContent, "/admindeleteusercomments": h.moderateContent, "/adminthreadaction": h.moderateContent,

		"/getgjlevellists": h.getLevelLists, "/uploadgjlevellist": h.uploadLevelList, "/deletegjlevellist": h.deleteLevelList, "/getgjcommenthistory": h.commentHistory,
		"/updategjusername": func(r request) (string, error) {
			return "1", h.accounts.UpdateLegacyUsername(r.ctx, r.str("udid"), r.str("userName"))
		},

		"/getgjaudiofilesize": h.utility,
		"/getgjcontentstatus": h.utility,
		"/getlevelrateinfo":   h.utility,
		"/getsavedata":        h.utility,
		"/openedregister":     h.utility,
		"/getgjsongusage":     h.songUsage, "/getgjtopartists": h.topArtists,

		"/rategjstars20": h.moderateLevel, "/rategjstars211": h.moderateLevel, "/rategjdemon21": h.moderateLevel, "/suggestgjstars20": h.moderateLevel, "/reportgjlevel": h.reportLevel,
		"/registergjaccount": h.register, "/logingjaccount": h.login,
		"/backupgjaccount": h.backup, "/backupgjaccount20": h.backup,
		"/syncgjaccount": h.sync, "/syncgjaccount20": h.sync,
		"/getaccounturl":       func(request) (string, error) { return strings.TrimRight(options.AccountURL, "/"), nil },
		"/getcustomcontenturl": func(request) (string, error) { return strings.TrimRight(options.ContentURL, "/"), nil },
		"/getgjmessages20":     h.messages, "/downloadgjmessage20": h.readMessage,
		"/uploadgjmessage20": h.sendMessage, "/deletegjmessages20": h.deleteMessage,
		"/uploadfriendrequest20": h.friendRequest, "/getgjfriendrequests20": h.friendRequests,
		"/readgjfriendrequest20": h.readFriendRequest, "/acceptgjfriendrequest20": h.acceptFriendRequest,
		"/deletegjfriendrequests20": h.deleteFriendRequest, "/removegjfriend20": h.removeFriend,
		"/blockgjuser20": h.block, "/unblockgjuser20": h.unblock, "/getgjuserlist20": h.userList,
		"/getgjlevels21": h.levelList, "/uploadgjlevel21": h.uploadLevel, "/uploadgjlevel22": h.uploadLevel,
		"/downloadgjlevel21": h.downloadLevel, "/downloadgjlevel22": h.downloadLevel,
		"/updategjlevel": h.checkLevelUpdate, "/updategjleveldesc20": h.updateLevelDescription, "/deletegjleveluser20": h.deleteLevel,
		"/followgjuser": h.follow, "/unfollowgjuser": h.unfollow, "/mutegjuser": h.mute,
		"/getgjfollowing": h.following, "/syncgjfollowing": h.syncFollowing,
		"/getgjnotifications": h.notifications, "/claimgjnotificationreward": h.claimNotification,
		"/getgjuserinfo20": h.userProfile, "/getgjusers20": h.searchUsers, "/getgjusersearch": h.searchUsers,
		"/getgjscores20": h.userScores, "/getgjcreators": h.creators, "/getgjcreators19": h.creators,
		"/requestuseraccess": h.requestAccess, "/updategjaccsettings20": h.updateSettings,
		"/updategjuserscore": h.updateUserScore, "/updategjuserscore22": h.updateUserScore,
		"/getgjcomments21": h.comments, "/getgjaccountcomments20": h.comments,
		"/uploadgjcomment20": h.uploadComment, "/uploadgjcomment21": h.uploadComment, "/uploadgjacccomment20": h.uploadComment,
		"/deletegjcomment20": h.deleteComment, "/deletegjacccomment20": h.deleteComment,
		"/likegjitem21": h.like, "/likegjitem211": h.like,
		"/getgjlevelscores": h.regularScores, "/getgjlevelscores211": h.regularScores, "/getgjlevelscoresplat": h.platformerScores,
		"/getgjrewards": h.rewards, "/getgjchallenges": h.challenges, "/getgjsecretreward": h.secretReward,
	}
	h.webRoutes = map[string]webEndpoint{
		"/lostusername": h.recovery,

		"/accountmanagement": h.accountManagement, "/profile/get": h.profileMedia, "/profile/upload": h.profileMedia, "/profile/remove": h.profileMedia, "/profile/image": h.profileMedia,

		"/muchocore/news":                   h.news,
		"/gd-content/news/news.dat":         h.news,
		"/gd-content/news/news_version.txt": h.news,
		"/news/news.dat":                    h.news,
		"/news/news_version.txt":            h.news,
		"/news/image":                       h.newsImage, "/muchoclient/manifest": h.clientBridge, "/muchoclient/negotiate": h.clientBridge,

		"/appearance/batch": h.appearance, "/appearance/my": h.appearance, "/appearance/save": h.appearance,

		"/clans/create":               h.clans,
		"/clans/my":                   h.clans,
		"/clans/get":                  h.clans,
		"/clans/search":               h.clans,
		"/clans/leaderboard":          h.clans,
		"/clans/join":                 h.clans,
		"/clans/leave":                h.clans,
		"/clans/invite":               h.clans,
		"/clans/invite/accept":        h.clans,
		"/clans/invite/decline":       h.clans,
		"/clans/kick":                 h.clans,
		"/clans/role":                 h.clans,
		"/clans/invites":              h.clans,
		"/clans/invites/sent":         h.clans,
		"/clans/settings":             h.clans,
		"/clans/transfer":             h.clans,
		"/clans/disband":              h.clans,
		"/clans/invite/revoke":        h.clans,
		"/clans/ban":                  h.clans,
		"/clans/unban":                h.clans,
		"/clans/bans":                 h.clans,
		"/clans/quests":               h.clanQuests,
		"/clans/quests/create":        h.clanQuests,
		"/clans/quests/cancel":        h.clanQuests,
		"/clans/quests/contributions": h.clanQuests,
		"/clans/activity":             h.clanQuests,
	}
	h.downloadKey = []byte(options.DownloadKey)
	if len(h.downloadKey) == 0 {
		h.downloadKey = make([]byte, 32)
		_, _ = rand.Read(h.downloadKey)
	}
	return h
}
func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if webassets.Serve(w, r) {
		return
	}
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	defer func() {
		if recover() != nil {
			if h.options.Logger != nil {
				h.options.Logger.Error("native request panicked")
			}
			w.WriteHeader(500)
			_, _ = fmt.Fprint(w, "-1")
		}
	}()
	path := discovery.NormalizePath(r.URL.EscapedPath())
	if path == "/health" || path == "/ready" || path == "/checkifserveronline" {
		h.discovery.ServeHTTP(w, r)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 10*time.Second)
	defer cancel()
	form := url.Values{}
	if r.Method == http.MethodPost {
		limit := int64(discovery.MaxBodyBytes)
		if path == "/backupgjaccount" || path == "/backupgjaccount20" || path == "/uploadgjlevel21" || path == "/uploadgjlevel22" {
			limit = 3*cloudsave.MaxSaveBytes + 1024*1024
		}
		if path == "/uploadgjeditorsound" {
			limit = 3*2800000 + 65536
		}
		if path == "/profile/upload" {
			limit = 3*5592412 + 65536
		}
		r.Body = http.MaxBytesReader(w, r.Body, limit)
		err := r.ParseForm()
		if err == nil && strings.HasPrefix(strings.ToLower(r.Header.Get("Content-Type")), "multipart/form-data") {
			err = r.ParseMultipartForm(discovery.MaxBodyBytes)
		}
		if r.MultipartForm != nil {
			defer r.MultipartForm.RemoveAll()
		}
		if err != nil {
			var size *http.MaxBytesError
			if errors.As(err, &size) {
				w.WriteHeader(413)
			} else {
				w.WriteHeader(400)
			}
			_, _ = fmt.Fprint(w, "-1")
			return
		}
		form = r.PostForm
		for key, values := range form {
			if len(values) > 1 {
				form[key] = values[len(values)-1:]
			}
		}
	}
	ip := security.ClientIP(r, h.options.TrustedProxies)
	req := request{ctx: ctx, form: form, path: path, ip: ip, version: protocol.VersionFrom(r.URL.EscapedPath(), form), raw: r}
	if !h.options.DisableProtect {
		ok, err := h.protect.Inspect(ctx, path, ip, form, req.version)
		if err != nil || !ok {
			_, _ = fmt.Fprint(w, "-1")
			return
		}
	}
	web, found := h.webRoutes[path]
	if !found && newsImagePath.MatchString(path) {
		web, found = h.newsImage, true
	}
	if found {
		fn := web
		response := fn(req)
		for key, values := range response.Headers {
			for _, value := range values {
				w.Header().Add(key, value)
			}
		}
		if response.ContentType != "" {
			w.Header().Set("Content-Type", response.ContentType)
		}
		if response.Status == 0 {
			response.Status = 200
		}
		w.WriteHeader(response.Status)
		_, _ = fmt.Fprint(w, response.Body)
		return
	}
	fn, ok := h.routes[path]
	if !ok {
		h.discovery.ServeHTTP(w, r.WithContext(ctx))
		return
	}
	body, err := fn(req)
	if err != nil {
		body = "-1"
		if h.options.Logger != nil && !errors.Is(err, account.ErrUnauthorized) && !errors.Is(err, sql.ErrNoRows) {
			h.options.Logger.Error("native endpoint failed", "path", path)
		}
	}
	_, _ = fmt.Fprint(w, body)
}
func (h *Handler) register(r request) (string, error) {
	return h.accounts.Register(r.ctx, r.str("userName"), r.str("password"), r.str("email"), r.ip)
}
func (h *Handler) login(r request) (string, error) {
	return h.accounts.Login(r.ctx, r.str("userName"), r.str("password"), r.str("gjp2"), r.ip, r.str("udid"))
}
func (h *Handler) cloudAccount(r request) (int64, error) {
	id := r.int("accountID")
	name := strings.TrimSpace(r.str("userName"))
	if name == "" {
		name = strings.TrimSpace(r.str("username"))
	}
	if id <= 0 && name != "" {
		if err := h.db.QueryRowContext(r.ctx, `SELECT account_id FROM accounts WHERE username=? LIMIT 1`, name).Scan(&id); err != nil {
			return 0, err
		}
	}
	credential := ""
	for _, key := range []string{"password", "gjp2", "gjp"} {
		if v := strings.TrimSpace(r.str(key)); v != "" {
			credential = v
			break
		}
	}
	identity, err := h.accounts.Authenticate(r.ctx, id, credential)
	return identity.ID, err
}
func (h *Handler) backup(r request) (string, error) {
	id, err := h.cloudAccount(r)
	if err != nil {
		return "", err
	}
	_, err = h.cloud.Save(r.ctx, id, r.str("saveData"))
	return "1", err
}
func (h *Handler) sync(r request) (string, error) {
	id, err := h.cloudAccount(r)
	if err != nil {
		return "", err
	}
	data, err := h.cloud.Load(r.ctx, id)
	if err != nil {
		return "", err
	}
	if data == "" {
		return "-1", nil
	}
	if !strings.Contains(data, ";21;30;a;a") {
		data += ";21;30;a;a"
	}
	return data, nil
}
func success(result sql.Result, err error) (string, error) {
	if err != nil {
		return "", err
	}
	count, err := result.RowsAffected()
	if err != nil {
		return "", err
	}
	if count == 0 {
		return "-1", nil
	}
	return "1", nil
}
