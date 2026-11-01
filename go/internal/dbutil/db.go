// Package dbutil provides context-aware row access for the shared Mucho schema.
package dbutil

import (
	"context"
	"database/sql"
	"fmt"
	"strconv"
	"strings"
	"time"
)

type Row map[string]string

func (r Row) Int(key string) int64     { n, _ := strconv.ParseInt(r[key], 10, 64); return n }
func (r Row) Float(key string) float64 { n, _ := strconv.ParseFloat(r[key], 64); return n }
func (r Row) Value(key, fallback string) string {
	if v, ok := r[key]; ok {
		return v
	}
	return fallback
}

type Querier interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
}

func Query(ctx context.Context, db Querier, query string, args ...any) ([]Row, error) {
	result, _, err := queryRows(ctx, db, query, args...)
	return result, err
}
func queryRows(ctx context.Context, db Querier, query string, args ...any) ([]Row, []*sql.ColumnType, error) {
	rows, err := db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	columns, err := rows.ColumnTypes()
	if err != nil {
		return nil, nil, err
	}
	var result []Row
	for rows.Next() {
		values := make([]any, len(columns))
		dest := make([]any, len(columns))
		for i := range values {
			dest[i] = &values[i]
		}
		if err := rows.Scan(dest...); err != nil {
			return nil, nil, err
		}
		row := Row{}
		for i, c := range columns {
			if values[i] == nil {
				continue
			}
			var value string
			switch v := values[i].(type) {
			case []byte:
				value = string(v)
			case time.Time:
				if c.DatabaseTypeName() == "DATE" {
					value = v.Format("2006-01-02")
					if v.IsZero() {
						value = "0000-00-00"
					}
				} else {
					value = v.Format("2006-01-02 15:04:05.999999")
					if v.IsZero() {
						value = "0000-00-00 00:00:00"
					}
				}
			default:
				value = fmt.Sprint(v)
			}
			row[c.Name()] = value
		}
		result = append(result, row)
	}
	return result, columns, rows.Err()
}

// TypedQuery retains SQL NULLs and native integer/float JSON types. DECIMAL
// stays a string, matching PDO without losing precision. Wire codecs use Row.
func TypedQuery(ctx context.Context, db Querier, query string, args ...any) ([]map[string]any, error) {
	rows, columns, err := queryRows(ctx, db, query, args...)
	if err != nil {
		return nil, err
	}
	result := make([]map[string]any, 0, len(rows))
	for _, row := range rows {
		typed := map[string]any{}
		for _, column := range columns {
			key := column.Name()
			value, ok := row[key]
			if !ok {
				typed[key] = nil
				continue
			}
			typed[key] = value
			typ := strings.ToUpper(column.DatabaseTypeName())
			if strings.Contains(typ, "INT") {
				if n, e := strconv.ParseInt(value, 10, 64); e == nil {
					typed[key] = n
				}
			} else if typ == "FLOAT" || typ == "DOUBLE" {
				if n, e := strconv.ParseFloat(value, 64); e == nil {
					typed[key] = n
				}
			}
		}
		result = append(result, typed)
	}
	return result, nil
}
func TypedOne(ctx context.Context, db Querier, query string, args ...any) (map[string]any, error) {
	rows, err := TypedQuery(ctx, db, query, args...)
	if err != nil {
		return nil, err
	}
	if len(rows) == 0 {
		return nil, sql.ErrNoRows
	}
	return rows[0], nil
}
func One(ctx context.Context, db Querier, query string, args ...any) (Row, error) {
	rows, err := Query(ctx, db, query, args...)
	if err != nil {
		return nil, err
	}
	if len(rows) == 0 {
		return nil, sql.ErrNoRows
	}
	return rows[0], nil
}
