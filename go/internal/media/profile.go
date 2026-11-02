package media

import (
	"bytes"
	"encoding/base64"
	"errors"
	"image"
	"image/color"
	"image/draw"
	"image/jpeg"
	_ "image/png"
	"math"

	xdraw "golang.org/x/image/draw"
)

func ProfileDimensions(kind string) (int, int, error) {
	switch kind {
	case "avatar":
		return 256, 256, nil
	case "banner":
		return 960, 320, nil
	}
	return 0, 0, errors.New("Choose avatar or banner.")
}

// RenderProfile decodes bounded JPEG/PNG input, applies the client's clockwise
// rotation and crop, flattens transparency and emits a fresh metadata-free JPEG.
// JPEG encoders differ across languages; stored PHP JPEGs remain readable as-is.
func RenderProfile(kind, encoded string, x, y, zoom, rotation int) ([]byte, error) {
	outW, outH, err := ProfileDimensions(kind)
	if err != nil {
		return nil, err
	}
	if x < 0 || x > 1000 || y < 0 || y > 1000 || zoom < 100 || zoom > 300 || (rotation != 0 && rotation != 90 && rotation != 180 && rotation != 270) {
		return nil, errors.New("Invalid crop.")
	}
	const maxInput = 4 * 1024 * 1024
	if len(encoded) == 0 || len(encoded) > 4*((maxInput+2)/3)+4 {
		return nil, errors.New("Choose a JPG or PNG up to 4 MB.")
	}
	data, err := base64.StdEncoding.Strict().DecodeString(encoded)
	if err != nil || len(data) > maxInput || base64.StdEncoding.EncodeToString(data) != encoded {
		return nil, errors.New("Invalid image data.")
	}
	config, format, err := image.DecodeConfig(bytes.NewReader(data))
	if err != nil || (format != "jpeg" && format != "png") {
		return nil, errors.New("Only JPG and PNG images are supported.")
	}
	w, h := config.Width, config.Height
	if w < 16 || h < 16 || w > 8192 || h > 8192 || int64(w)*int64(h) > 12000000 {
		return nil, errors.New("Image must be at least 16 pixels and at most 12 megapixels.")
	}
	source, _, err := image.Decode(bytes.NewReader(data))
	if err != nil {
		return nil, errors.New("The image is damaged or unsupported.")
	}
	if rotation != 0 {
		rw, rh := w, h
		if rotation == 90 || rotation == 270 {
			rw, rh = h, w
		}
		turned := image.NewNRGBA(image.Rect(0, 0, rw, rh))
		for py := range rh {
			for px := range rw {
				sx, sy := 0, 0
				switch rotation {
				case 90:
					sx, sy = py, h-1-px
				case 180:
					sx, sy = w-1-px, h-1-py
				case 270:
					sx, sy = w-1-py, px
				}
				turned.Set(px, py, source.At(sx, sy))
			}
		}
		source = turned
		w, h = rw, rh
	}
	scale := max(float64(outW)/float64(w), float64(outH)/float64(h)) * float64(zoom) / 100
	cropW, cropH := min(w, int(math.Round(float64(outW)/scale))), min(h, int(math.Round(float64(outH)/scale)))
	left, top := int(math.Round(float64(w-cropW)*float64(x)/1000)), int(math.Round(float64(h-cropH)*float64(y)/1000))
	target := image.NewRGBA(image.Rect(0, 0, outW, outH))
	draw.Draw(target, target.Bounds(), image.NewUniform(color.RGBA{27, 41, 65, 255}), image.Point{}, draw.Src)
	xdraw.CatmullRom.Scale(target, target.Bounds(), source, image.Rect(left, top, left+cropW, top+cropH), draw.Over, nil)
	var output bytes.Buffer
	if err = jpeg.Encode(&output, target, &jpeg.Options{Quality: 88}); err != nil {
		return nil, errors.New("Could not encode the image.")
	}
	if output.Len() == 0 || output.Len() > 512*1024 {
		return nil, errors.New("Could not compress the image.")
	}
	return output.Bytes(), nil
}
