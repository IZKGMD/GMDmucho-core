package media

import (
	"io"
)

// MP3 inspects an optional bounded ID3v2 tag and MPEG Layer III frame headers.
// The multipart filename and client-supplied MIME type are never authoritative.
func MP3(reader io.ReadSeeker, size int64) bool {
	if size < 4 {
		return false
	}
	if _, err := reader.Seek(0, io.SeekStart); err != nil {
		return false
	}
	header := make([]byte, 10)
	n, _ := io.ReadFull(reader, header)
	offset := int64(0)
	if n >= 10 && string(header[:3]) == "ID3" {
		if header[3] < 2 || header[3] > 4 {
			return false
		}
		for _, b := range header[6:10] {
			if b&128 != 0 {
				return false
			}
		}
		offset = 10 + (int64(header[6]) << 21) + (int64(header[7]) << 14) + (int64(header[8]) << 7) + int64(header[9])
		if header[3] == 4 && header[5]&16 != 0 {
			offset += 10
		}
		if offset > size-4 {
			return false
		}
	}
	if _, err := reader.Seek(offset, io.SeekStart); err != nil {
		return false
	}
	prefix := make([]byte, min(int64(4096), size-offset))
	n, _ = io.ReadFull(reader, prefix)
	prefix = prefix[:n]
	for i := 0; i+4 <= len(prefix); i++ {
		frameLength, version, rate := mp3Frame(prefix[i : i+4])
		if frameLength == 0 || offset+int64(i+frameLength) > size {
			continue
		}
		next := offset + int64(i+frameLength)
		if next == size {
			return true
		}
		if _, err := reader.Seek(next, io.SeekStart); err != nil {
			return false
		}
		probe := make([]byte, 4)
		if _, err := io.ReadFull(reader, probe); err != nil {
			continue
		}
		length, nextVersion, nextRate := mp3Frame(probe)
		if length > 0 && version == nextVersion && rate == nextRate && next+int64(length) <= size {
			return true
		}
	}
	return false
}
func mp3Frame(header []byte) (length, version, sampleRate int) {
	if len(header) < 4 || header[0] != 255 || header[1]&224 != 224 {
		return
	}
	version = int(header[1] >> 3 & 3)
	if version == 1 || header[1]>>1&3 != 1 {
		return 0, 0, 0
	}
	bitrateIndex := int(header[2] >> 4)
	rateIndex := int(header[2] >> 2 & 3)
	if bitrateIndex == 0 || bitrateIndex == 15 || rateIndex == 3 {
		return 0, 0, 0
	}
	sampleRate = []int{44100, 48000, 32000}[rateIndex]
	bitrate := []int{0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320}[bitrateIndex]
	coefficient := 144
	if version != 3 {
		coefficient = 72
		bitrate = []int{0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160}[bitrateIndex]
		sampleRate /= 2
		if version == 0 {
			sampleRate /= 2
		}
	}
	length = coefficient*bitrate*1000/sampleRate + int(header[2]>>1&1)
	return
}
