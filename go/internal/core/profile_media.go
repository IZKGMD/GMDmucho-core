package core

import (
	"crypto/sha256"
	"database/sql"
	"errors"
	"fmt"
	"regexp"

	"github.com/IZKGMD/GMDmucho-core/go/internal/dbutil"
	"github.com/IZKGMD/GMDmucho-core/go/internal/media"
)

var profileVersion = regexp.MustCompile(`^[a-f0-9]{64}$`)
var cropInteger = regexp.MustCompile(`^[0-9]{1,4}$`)

func (h *Handler) profileMedia(r request) reply {
	if r.raw.Method != "POST" {
		return jsonReply(map[string]any{"ok": false, "error": "Use POST."}, 405)
	}
	data, image, err := h.profileMediaData(r)
	if err != nil {
		var exposed publicError
		if !errors.As(err, &exposed) {
			err = publicError("Profile storage is unavailable. Update MuchoCore and check the server logs.")
		}
		return apiReply(nil, err)
	}
	if r.path == "/profile/image" {
		if image == nil {
			return jsonReply(map[string]any{"ok": false, "error": "Image not found."}, 404)
		}
		return reply{Body: string(image), Status: 200, ContentType: "image/jpeg"}
	}
	return apiReply(data, nil)
}
func (h *Handler) profileMediaData(r request) (any, []byte, error) {
	id, kind := r.int("accountID"), r.str("kind")
	if r.path == "/profile/image" {
		if _, _, err := media.ProfileDimensions(kind); err != nil {
			return nil, nil, publicError(err.Error())
		}
		if id <= 0 || id > 2147483647 || !profileVersion.MatchString(r.str("version")) {
			return nil, nil, publicError("Invalid profile image.")
		}
		var image []byte
		err := h.db.QueryRowContext(r.ctx, `SELECT m.image_data FROM mucho_profile_media m JOIN accounts a ON a.account_id=m.account_id WHERE m.account_id=? AND m.kind=? AND m.version=? AND a.is_active=1 AND a.is_banned=0`, id, kind, r.str("version")).Scan(&image)
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil, nil
		}
		return nil, image, err
	}
	if r.path != "/profile/get" {
		// Crop fields are parsed by the PHP HTTP adapter before authentication.
		values := []int{500, 500, 100, 0}
		if r.path == "/profile/upload" {
			for i, key := range []string{"cropX", "cropY", "zoom", "rotation"} {
				if _, ok := r.form[key]; ok {
					if !cropInteger.MatchString(r.str(key)) {
						return nil, nil, publicError("Invalid crop.")
					}
					values[i] = int(r.int(key))
				}
			}
		}
		me, err := h.auth(r)
		if err != nil {
			return nil, nil, publicError("Unauthorized.")
		}
		id = me.ID
		if r.path == "/profile/upload" {
			jpeg, err := media.RenderProfile(kind, r.str("imageData"), values[0], values[1], values[2], values[3])
			if err != nil {
				return nil, nil, publicError(err.Error())
			}
			_, err = h.db.ExecContext(r.ctx, `INSERT INTO mucho_profile_media(account_id,kind,version,image_data) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),image_data=VALUES(image_data)`, id, kind, fmt.Sprintf("%x", sha256.Sum256(jpeg)), jpeg)
			if err != nil {
				return nil, nil, err
			}
		} else {
			if _, _, err = media.ProfileDimensions(kind); err != nil {
				return nil, nil, publicError(err.Error())
			}
			if _, err = h.db.ExecContext(r.ctx, `DELETE FROM mucho_profile_media WHERE account_id=? AND kind=?`, id, kind); err != nil {
				return nil, nil, err
			}
		}
	}
	if id <= 0 || id > 2147483647 {
		return nil, nil, publicError("Invalid account ID.")
	}
	players, err := h.appearances(r, []any{id})
	if err != nil {
		return nil, nil, err
	}
	if len(players) == 0 {
		return nil, nil, publicError("Profile not found.")
	}
	player := players[0]
	stats, err := dbutil.TypedOne(r.ctx, h.db, `SELECT stars,moons,diamonds,demons,secret_coins AS coins,user_coins,creator_points FROM profiles WHERE account_id=? LIMIT 1`, id)
	if errors.Is(err, sql.ErrNoRows) {
		player["stats"] = []any{}
	} else if err != nil {
		return nil, nil, err
	} else {
		for key, value := range stats {
			if key == "creator_points" {
				var v float64
				fmt.Sscan(fmt.Sprint(value), &v)
				stats[key] = int64(v)
			} else {
				stats[key] = anyInt(value)
			}
		}
		player["stats"] = stats
	}
	rows, err := dbutil.Query(r.ctx, h.db, `SELECT kind,version,OCTET_LENGTH(image_data) AS bytes FROM mucho_profile_media WHERE account_id=?`, id)
	if err != nil {
		return nil, nil, err
	}
	images := map[string]any{"avatar": nil, "banner": nil}
	for _, row := range rows {
		w, h, err := media.ProfileDimensions(row["kind"])
		if err != nil {
			return nil, nil, publicError(err.Error())
		}
		images[row["kind"]] = map[string]any{"version": row["version"], "bytes": row.Int("bytes"), "width": w, "height": h}
	}
	player["media"] = images
	return player, nil, nil
}
