package core

import (
	"bytes"
	"encoding/json"
	"errors"
	"net/http"
)

type reply struct {
	Body        string
	Status      int
	ContentType string
	Headers     http.Header
}
type webEndpoint func(request) reply
type publicError string

func (e publicError) Error() string { return string(e) }
func jsonReply(value any, status int) reply {
	var body bytes.Buffer
	encoder := json.NewEncoder(&body)
	encoder.SetEscapeHTML(false)
	if err := encoder.Encode(value); err != nil {
		return reply{Body: `{"ok":false,"error":"Cannot encode response."}`, Status: 500, ContentType: "application/json; charset=utf-8"}
	}
	return reply{Body: string(bytes.TrimSuffix(body.Bytes(), []byte("\n"))), Status: status, ContentType: "application/json; charset=utf-8"}
}
func apiReply(data any, err error) reply {
	if err == nil {
		return jsonReply(map[string]any{"ok": true, "data": data}, 200)
	}
	message := "Storage is unavailable. Check the server logs."
	var exposed publicError
	if errors.As(err, &exposed) {
		message = string(exposed)
	}
	return jsonReply(map[string]any{"ok": false, "error": message}, 400)
}
