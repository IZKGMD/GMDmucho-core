package integration

import (
	"bytes"
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"html"
	"io"
	"mime"
	"mime/multipart"
	"mime/quotedprintable"
	"net"
	"net/mail"
	"net/smtp"
	"net/textproto"
	"strconv"
	"strings"
	"time"
)

type RecoveryMailer interface {
	Recovery(context.Context, string, string, string) error
}
type SMTP struct {
	Host, Username, Password, From string
	Port                           int
	ImplicitTLS                    bool
}

func (s SMTP) Recovery(ctx context.Context, recipient, username, link string) error {
	if s.Host == "" || strings.ContainsAny(s.Host, "\r\n") {
		return errors.New("recovery SMTP is not configured")
	}
	from, err := mail.ParseAddress(s.From)
	if err != nil || strings.ContainsAny(s.From, "\r\n") {
		return errors.New("invalid SMTP sender")
	}
	to, err := mail.ParseAddress(recipient)
	if err != nil || strings.ContainsAny(recipient, "\r\n") {
		return errors.New("invalid recipient")
	}
	port := s.Port
	if port <= 0 {
		port = 587
	}
	address := net.JoinHostPort(s.Host, strconv.Itoa(port))
	dialer := net.Dialer{Timeout: 5 * time.Second}
	var connection net.Conn
	config := &tls.Config{ServerName: s.Host, MinVersion: tls.VersionTLS12}
	if s.ImplicitTLS {
		connection, err = (&tls.Dialer{NetDialer: &dialer, Config: config}).DialContext(ctx, "tcp", address)
	} else {
		connection, err = dialer.DialContext(ctx, "tcp", address)
	}
	if err != nil {
		return errors.New("SMTP connection failed")
	}
	defer connection.Close()
	deadline := time.Now().Add(8 * time.Second)
	if limit, ok := ctx.Deadline(); ok && limit.Before(deadline) {
		deadline = limit
	}
	if err = connection.SetDeadline(deadline); err != nil {
		return errors.New("SMTP deadline failed")
	}
	client, err := smtp.NewClient(connection, s.Host)
	if err != nil {
		return errors.New("SMTP handshake failed")
	}
	defer client.Close()
	if !s.ImplicitTLS {
		if supported, _ := client.Extension("STARTTLS"); supported {
			if err = client.StartTLS(config); err != nil {
				return errors.New("SMTP TLS failed")
			}
		} else {
			ip := net.ParseIP(s.Host)
			if ip == nil || !ip.IsLoopback() {
				return errors.New("SMTP server requires TLS")
			}
		}
	}
	if s.Username != "" {
		if err = client.Auth(smtp.PlainAuth("", s.Username, s.Password, s.Host)); err != nil {
			return errors.New("SMTP authentication failed")
		}
	}
	message, err := recoveryMessage(from, to, username, link)
	if err != nil {
		return err
	}
	if err = client.Mail(from.Address); err != nil {
		return errors.New("SMTP sender rejected")
	}
	if err = client.Rcpt(to.Address); err != nil {
		return errors.New("SMTP recipient rejected")
	}
	writer, err := client.Data()
	if err != nil {
		return errors.New("SMTP DATA failed")
	}
	if _, err = writer.Write(message); err != nil {
		writer.Close()
		return errors.New("SMTP delivery failed")
	}
	if err = writer.Close(); err != nil {
		return errors.New("SMTP delivery failed")
	}
	if err = client.Quit(); err != nil {
		return errors.New("SMTP quit failed")
	}
	return nil
}
func recoveryMessage(from, to *mail.Address, username, link string) ([]byte, error) {
	var body bytes.Buffer
	multi := multipart.NewWriter(&body)
	for _, part := range []struct{ kind, text string }{{"text/plain", "Hello, " + username + "!\n\nTo recover your MuchoCore account, open this link:\n" + link + "\n\nThis link is valid for 30 minutes and can only be used once.\nIf you did not request this recovery email, you can safely ignore it.\n"}, {"text/html", "<!doctype html><html lang=\"en\"><body><h2>MuchoCore Account Recovery</h2><p>Hello, " + html.EscapeString(username) + ".</p><p><a href=\"" + html.EscapeString(link) + "\">Recover account</a></p><p>This link is valid for 30 minutes and can only be used once.</p><p>If you did not request this recovery email, you can safely ignore it.</p></body></html>"}} {
		header := textproto.MIMEHeader{"Content-Type": {part.kind + "; charset=UTF-8"}, "Content-Transfer-Encoding": {"quoted-printable"}}
		writer, err := multi.CreatePart(header)
		if err != nil {
			return nil, errors.New("mail encoding failed")
		}
		encoder := quotedprintable.NewWriter(writer)
		if _, err = io.WriteString(encoder, part.text); err != nil {
			return nil, errors.New("mail encoding failed")
		}
		if err = encoder.Close(); err != nil {
			return nil, errors.New("mail encoding failed")
		}
	}
	if err := multi.Close(); err != nil {
		return nil, errors.New("mail encoding failed")
	}
	var message bytes.Buffer
	fmt.Fprintf(&message, "From: %s\r\nTo: %s\r\nSubject: %s\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=%q\r\nX-Auto-Response-Suppress: All\r\n\r\n", from.String(), to.String(), mime.BEncoding.Encode("UTF-8", "MuchoCore — Account recovery"), multi.Boundary())
	message.Write(body.Bytes())
	return message.Bytes(), nil
}
