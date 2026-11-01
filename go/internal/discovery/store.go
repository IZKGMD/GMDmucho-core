package discovery

import (
	"context"
	"database/sql"

	"github.com/IZKGMD/GMDmucho-core/go/internal/protocol"
)

// Store contains only the read operations implemented by the native preview.
// Missing records return sql.ErrNoRows; database errors remain distinguishable.
type Store interface {
	Ping(context.Context) error
	Song(context.Context, int64) (protocol.Song, error)
	Gauntlets(context.Context) ([]protocol.Gauntlet, error)
	MapPacks(context.Context, int) ([]protocol.MapPack, int, error)
	Daily(context.Context, string) (int64, int64, error)
}

type SQLStore struct{ DB *sql.DB }

func (s SQLStore) Ping(ctx context.Context) error { return s.DB.PingContext(ctx) }

func (s SQLStore) Song(ctx context.Context, id int64) (protocol.Song, error) {
	var song protocol.Song
	// is_disabled is absent in the canonical schema: PHP defaults wire field 8
	// to zero. Verification is a visibility filter, not a disabled flag.
	err := s.DB.QueryRowContext(ctx, `SELECT id, name, author_id, author_name, size,
		COALESCE(youtube_video_id, ''), download_url, COALESCE(youtube_channel_id, '')
		FROM songs WHERE id = ? AND is_verified = 1 LIMIT 1`, id).Scan(
		&song.ID, &song.Name, &song.AuthorID, &song.AuthorName, &song.Size,
		&song.VideoID, &song.DownloadURL, &song.ChannelID)
	return song, err
}

func (s SQLStore) Gauntlets(ctx context.Context) ([]protocol.Gauntlet, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT id, level1, level2, level3, level4, level5
		FROM mucho_gauntlets WHERE enabled = 1 AND level1 > 0 AND level2 > 0
		AND level3 > 0 AND level4 > 0 AND level5 > 0 ORDER BY sort_order ASC, id ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var result []protocol.Gauntlet
	for rows.Next() {
		var g protocol.Gauntlet
		if err := rows.Scan(&g.ID, &g.Levels[0], &g.Levels[1], &g.Levels[2], &g.Levels[3], &g.Levels[4]); err != nil {
			return nil, err
		}
		result = append(result, g)
	}
	return result, rows.Err()
}

func (s SQLStore) MapPacks(ctx context.Context, offset int) ([]protocol.MapPack, int, error) {
	var total int
	if err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM mucho_map_packs WHERE enabled = 1`).Scan(&total); err != nil {
		return nil, 0, err
	}
	if total == 0 {
		return nil, 0, nil
	}
	rows, err := s.DB.QueryContext(ctx, `SELECT id, name, levels, stars, coins, difficulty, color1, color2
		FROM mucho_map_packs WHERE enabled = 1 ORDER BY sort_order ASC, id ASC LIMIT 10 OFFSET ?`, offset)
	if err != nil {
		return nil, 0, err
	}
	defer rows.Close()
	var result []protocol.MapPack
	for rows.Next() {
		var p protocol.MapPack
		if err := rows.Scan(&p.ID, &p.Name, &p.Levels, &p.Stars, &p.Coins, &p.Difficulty, &p.Color1, &p.Color2); err != nil {
			return nil, 0, err
		}
		result = append(result, p)
	}
	return result, total, rows.Err()
}

func (s SQLStore) Daily(ctx context.Context, kind string) (int64, int64, error) {
	var id, remaining int64
	err := s.DB.QueryRowContext(ctx, `SELECT id,
		GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), ends_at)) AS remaining
		FROM mucho_daily_rotation WHERE kind = ? AND enabled = 1
		AND starts_at <= UTC_TIMESTAMP() AND ends_at > UTC_TIMESTAMP()
		ORDER BY starts_at DESC, id DESC LIMIT 1`, kind).Scan(&id, &remaining)
	return id, remaining, err
}
