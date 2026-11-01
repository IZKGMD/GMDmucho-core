// Package protocol implements Geometry Dash's byte-oriented wire formats.
package protocol

import (
	"crypto/sha1"
	"encoding/base64"
	"fmt"
	"math"
	"math/big"
	"strconv"
	"strings"
)

const salt = "xI25fpAapCQg"

func MetadataHash(data string) string { return fmt.Sprintf("%x", sha1.Sum([]byte(data+salt))) }
func RewardsHash(data string) string  { return fmt.Sprintf("%x", sha1.Sum([]byte(data+"pC26fpYaQCtg"))) }

func LevelHash(data string) string {
	if len(data) < 41 {
		return MetadataHash(data)
	}
	var sample [40]byte
	for i := range sample {
		sample[i] = data[i*(len(data)/40)]
	}
	return MetadataHash(string(sample[:]))
}

func XOR(text, key string) string {
	if key == "" {
		return text
	}
	result := []byte(text)
	for i := range result {
		result[i] ^= key[i%len(key)]
	}
	return string(result)
}

func CopyPassword(password string) string {
	if password == "" || password == "0" {
		return "0"
	}
	return base64.StdEncoding.EncodeToString([]byte(XOR(password, "26364")))
}

// Username deliberately truncates bytes, as the PHP protocol encoder does.
func Username(value string) string {
	value = strings.Trim(value, " \t\n\r\x00\x0b")
	var b strings.Builder
	for i := 0; i < len(value); i++ {
		c := value[i]
		if c < 32 || c == 127 || strings.ContainsRune(":|#~", rune(c)) {
			continue
		}
		b.WriteByte(c)
	}
	value = b.String()
	if len(value) > 20 {
		value = value[:20]
	}
	if value == "" {
		return "Player"
	}
	return value
}

type Song struct {
	ID, AuthorID                    int64
	Name, AuthorName                string
	Size                            float64
	VideoID, DownloadURL, ChannelID string
	Disabled                        int
}

func EncodeSong(s Song) string {
	return strings.Join([]string{
		"1", strconv.FormatInt(s.ID, 10), "2", Username(s.Name),
		"3", strconv.FormatInt(s.AuthorID, 10), "4", Username(s.AuthorName),
		"5", roundedSize(s.Size),
		"6", s.VideoID, "10", s.DownloadURL, "7", s.ChannelID,
		"8", strconv.Itoa(s.Disabled),
	}, "~|~")
}

// PHP round(..., 2) uses decimal half-away-from-zero. Multiplying a binary
// float by 100 first would incorrectly encode values such as 0.145 as 0.14.
func roundedSize(size float64) string {
	r, ok := new(big.Rat).SetString(strconv.FormatFloat(size, 'f', -1, 64))
	if !ok {
		return "0"
	}
	negative := math.Signbit(size)
	num := new(big.Int).Abs(r.Num())
	num.Mul(num, big.NewInt(100))
	cents, remainder := new(big.Int), new(big.Int)
	cents.QuoRem(num, r.Denom(), remainder)
	if remainder.Lsh(remainder, 1).Cmp(r.Denom()) >= 0 {
		cents.Add(cents, big.NewInt(1))
	}
	value := cents.String()
	for len(value) < 3 {
		value = "0" + value
	}
	value = strings.TrimRight(strings.TrimRight(value[:len(value)-2]+"."+value[len(value)-2:], "0"), ".")
	if negative {
		value = "-" + value
	}
	return value
}

type Gauntlet struct {
	ID     int64
	Levels [5]int64
}

func EncodeGauntlets(rows []Gauntlet) string {
	if len(rows) == 0 {
		return "-1"
	}
	parts := make([]string, 0, len(rows))
	var hash strings.Builder
	for _, g := range rows {
		levels := make([]string, 5)
		for i, id := range g.Levels {
			levels[i] = strconv.FormatInt(id, 10)
		}
		id, list := strconv.FormatInt(g.ID, 10), strings.Join(levels, ",")
		parts = append(parts, "1:"+id+":3:"+list)
		hash.WriteString(id + list)
	}
	return strings.Join(parts, "|") + "#" + MetadataHash(hash.String())
}

type MapPack struct {
	ID                       int64
	Name, Levels             string
	Stars, Coins, Difficulty int
	Color1, Color2           string
}

func EncodeMapPacks(rows []MapPack, total, offset int) string {
	if total == 0 || len(rows) == 0 {
		return "-1"
	}
	parts := make([]string, 0, len(rows))
	var hash strings.Builder
	clean := strings.NewReplacer(":", "", "|", "", "#", "", "~", "")
	for _, p := range rows {
		id := strconv.FormatInt(p.ID, 10)
		parts = append(parts, fmt.Sprintf("1:%s:2:%s:3:%s:4:%d:5:%d:6:%d:7:%s:8:%s",
			id, clean.Replace(p.Name), p.Levels, p.Stars, p.Coins, p.Difficulty, p.Color1, p.Color2))
		fmt.Fprintf(&hash, "%c%c%d%d", id[0], id[len(id)-1], p.Stars, p.Coins)
	}
	return fmt.Sprintf("%s#%d:%d:10#%s", strings.Join(parts, "|"), total, offset, MetadataHash(hash.String()))
}
