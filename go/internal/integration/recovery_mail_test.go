package integration

import (
	"bufio"
	"context"
	"io"
	"mime"
	"mime/multipart"
	"mime/quotedprintable"
	"net"
	"net/mail"
	"net/textproto"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestRecoverySMTPToLocalTestServer(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	delivered := make(chan string, 1)
	serverError := make(chan error, 1)
	go func() {
		connection, err := listener.Accept()
		if err != nil {
			serverError <- err
			return
		}
		defer connection.Close()
		connection.SetDeadline(time.Now().Add(5 * time.Second))
		server := textproto.NewConn(connection)
		server.PrintfLine("220 native-test ESMTP")
		for {
			line, err := server.ReadLine()
			if err != nil {
				serverError <- err
				return
			}
			switch {
			case strings.HasPrefix(line, "EHLO"), strings.HasPrefix(line, "HELO"):
				server.PrintfLine("250 native-test")
			case strings.HasPrefix(line, "MAIL FROM"), strings.HasPrefix(line, "RCPT TO"):
				server.PrintfLine("250 OK")
			case line == "DATA":
				server.PrintfLine("354 send message")
				body, err := server.ReadDotBytes()
				if err != nil {
					serverError <- err
					return
				}
				delivered <- string(body)
				server.PrintfLine("250 queued")
			case line == "QUIT":
				server.PrintfLine("221 bye")
				serverError <- nil
				return
			default:
				server.PrintfLine("500 unknown command")
			}
		}
	}()
	_, portRaw, _ := net.SplitHostPort(listener.Addr().String())
	port, _ := strconv.Atoi(portRaw)
	sender := SMTP{Host: "127.0.0.1", Port: port, From: "MuchoCore <recovery@example.invalid>"}
	if err = sender.Recovery(context.Background(), "player@example.invalid", "Player <test>", "https://example.invalid/recover?token=test-token"); err != nil {
		t.Fatal(err)
	}
	if err = <-serverError; err != nil {
		t.Fatal(err)
	}
	message, err := mail.ReadMessage(bufio.NewReader(strings.NewReader(<-delivered)))
	if err != nil {
		t.Fatal(err)
	}
	_, params, err := mime.ParseMediaType(message.Header.Get("Content-Type"))
	if err != nil {
		t.Fatal(err)
	}
	reader := multipart.NewReader(message.Body, params["boundary"])
	plain, html := "", ""
	for {
		part, err := reader.NextRawPart()
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		body, err := io.ReadAll(quotedprintable.NewReader(part))
		if err != nil {
			t.Fatal(err)
		}
		if strings.HasPrefix(part.Header.Get("Content-Type"), "text/plain") {
			plain = string(body)
		} else {
			html = string(body)
		}
	}
	if !strings.Contains(plain, "https://example.invalid/recover?token=test-token") || !strings.Contains(html, "Player &lt;test&gt;") {
		t.Fatal("incomplete or unsafe recovery message")
	}
}
