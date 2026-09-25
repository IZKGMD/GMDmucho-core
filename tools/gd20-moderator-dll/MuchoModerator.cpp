#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <winhttp.h>
#include <wincrypt.h>
#include <string>
#include <vector>
#include <sstream>
#include <iomanip>
#include <algorithm>

#pragma comment(lib, "winhttp.lib")
#pragma comment(lib, "crypt32.lib")

namespace {
constexpr wchar_t kClassName[] = L"MuchoModeratorWindow";
constexpr wchar_t kTitle[] = L"Mucho Moderator - GD 2.0";
constexpr int HOTKEY_ID = 0x4D4D;

HWND gWnd = nullptr;
HWND gLevel = nullptr;
HWND gAccount = nullptr;
HWND gGjp = nullptr;
HWND gStars = nullptr;
HWND gFeature = nullptr;
HWND gDemon = nullptr;
HWND gCoins = nullptr;
HWND gStatus = nullptr;

std::wstring trim(std::wstring s) {
    size_t a = s.find_first_not_of(L" \t\r\n");
    size_t b = s.find_last_not_of(L" \t\r\n");
    if (a == std::wstring::npos) return L"";
    return s.substr(a, b - a + 1);
}

std::wstring getText(HWND h) {
    int n = GetWindowTextLengthW(h);
    std::wstring s(static_cast<size_t>(n), L'\0');
    if (n > 0) GetWindowTextW(h, s.data(), n + 1);
    return s;
}

void setStatus(const std::wstring& text) {
    if (gStatus) SetWindowTextW(gStatus, text.c_str());
}

std::wstring formEncode(const std::wstring& in) {
    std::wostringstream out;
    out << std::uppercase << std::hex << std::setfill(L'0');
    for (wchar_t wc : in) {
        unsigned int c = static_cast<unsigned int>(wc);
        bool safe =
            (c >= L'a' && c <= L'z') ||
            (c >= L'A' && c <= L'Z') ||
            (c >= L'0' && c <= L'9') ||
            wc == L'-' || wc == L'_' || wc == L'.' || wc == L'~';
        if (safe) out << wc;
        else if (c < 0x80) out << L'%' << std::setw(2) << c;
        else {
            // UTF-8 encode the codepoint for form posts.
            if (c <= 0x7FF) {
                unsigned char b1 = static_cast<unsigned char>(0xC0 | (c >> 6));
                unsigned char b2 = static_cast<unsigned char>(0x80 | (c & 0x3F));
                out << L'%' << std::setw(2) << static_cast<unsigned int>(b1)
                    << L'%' << std::setw(2) << static_cast<unsigned int>(b2);
            } else {
                unsigned char b1 = static_cast<unsigned char>(0xE0 | (c >> 12));
                unsigned char b2 = static_cast<unsigned char>(0x80 | ((c >> 6) & 0x3F));
                unsigned char b3 = static_cast<unsigned char>(0x80 | (c & 0x3F));
                out << L'%' << std::setw(2) << static_cast<unsigned int>(b1)
                    << L'%' << std::setw(2) << static_cast<unsigned int>(b2)
                    << L'%' << std::setw(2) << static_cast<unsigned int>(b3);
            }
        }
    }
    return out.str();
}

bool httpPost(const std::wstring& path, const std::wstring& body, std::wstring& response) {
    URL_COMPONENTSW parts{};
    wchar_t host[256]{};
    wchar_t urlPath[2048]{};
    parts.dwStructSize = sizeof(parts);
    parts.lpszHostName = host;
    parts.dwHostNameLength = ARRAYSIZE(host);
    parts.lpszUrlPath = urlPath;
    parts.dwUrlPathLength = ARRAYSIZE(urlPath);

    std::wstring url = L"https://muchogdps.space" + path;
    if (!WinHttpCrackUrl(url.c_str(), 0, 0, &parts)) return false;

    HINTERNET session = WinHttpOpen(
        L"MuchoModerator/1.0",
        WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
        WINHTTP_NO_PROXY_NAME,
        WINHTTP_NO_PROXY_BYPASS,
        0
    );
    if (!session) return false;

    HINTERNET connect = WinHttpConnect(session, host, parts.nPort, 0);
    if (!connect) {
        WinHttpCloseHandle(session);
        return false;
    }

    HINTERNET request = WinHttpOpenRequest(
        connect, L"POST", urlPath, nullptr,
        WINHTTP_NO_REFERER,
        WINHTTP_DEFAULT_ACCEPT_TYPES,
        WINHTTP_FLAG_SECURE
    );
    if (!request) {
        WinHttpCloseHandle(connect);
        WinHttpCloseHandle(session);
        return false;
    }

    const wchar_t* headers = L"Content-Type: application/x-www-form-urlencoded\r\n";
    int bodyLen = WideCharToMultiByte(CP_UTF8, 0, body.c_str(), static_cast<int>(body.size()), nullptr, 0, nullptr, nullptr);
    std::string utf8(static_cast<size_t>(bodyLen), '\0');
    if (bodyLen > 0) {
        WideCharToMultiByte(CP_UTF8, 0, body.c_str(), static_cast<int>(body.size()), utf8.data(), bodyLen, nullptr, nullptr);
    }

    BOOL ok = WinHttpSendRequest(
        request, headers, static_cast<DWORD>(-1L),
        utf8.empty() ? WINHTTP_NO_REQUEST_DATA : utf8.data(),
        static_cast<DWORD>(utf8.size()),
        static_cast<DWORD>(utf8.size()),
        0
    );

    if (ok) ok = WinHttpReceiveResponse(request, nullptr);

    if (ok) {
        std::string buf;
        for (;;) {
            DWORD avail = 0;
            if (!WinHttpQueryDataAvailable(request, &avail) || avail == 0) break;
            size_t old = buf.size();
            buf.resize(old + avail);
            DWORD got = 0;
            if (!WinHttpReadData(request, buf.data() + old, avail, &got)) {
                ok = FALSE;
                break;
            }
            buf.resize(old + got);
        }
        int wlen = MultiByteToWideChar(CP_UTF8, 0, buf.data(), static_cast<int>(buf.size()), nullptr, 0);
        response.assign(static_cast<size_t>(wlen), L'\0');
        if (wlen > 0) {
            MultiByteToWideChar(CP_UTF8, 0, buf.data(), static_cast<int>(buf.size()), response.data(), wlen);
        }
    }

    WinHttpCloseHandle(request);
    WinHttpCloseHandle(connect);
    WinHttpCloseHandle(session);
    return ok == TRUE;
}

int comboIndex(HWND combo) {
    return static_cast<int>(SendMessageW(combo, CB_GETCURSEL, 0, 0));
}

void submitSuggest() {
    std::wstring level = trim(getText(gLevel));
    std::wstring account = trim(getText(gAccount));
    std::wstring gjp = trim(getText(gGjp));
    if (level.empty() || account.empty() || gjp.empty()) {
        setStatus(L"Fill Level ID, Account ID and GJP.");
        return;
    }

    std::wstring stars = std::to_wstring(comboIndex(gStars) + 1);
    std::wstring feature = std::to_wstring(comboIndex(gFeature));
    std::wstring demon = std::to_wstring(comboIndex(gDemon));
    int coins = (Button_GetCheck(gCoins) == BST_CHECKED) ? 1 : 0;

    std::wstring body =
        L"accountID=" + formEncode(account) +
        L"&gjp=" + formEncode(gjp) +
        L"&levelID=" + formEncode(level) +
        L"&stars=" + stars +
        L"&feature=" + feature +
        L"&coins=" + std::to_wstring(coins);

    setStatus(L"Sending suggestion...");
    std::wstring response;
    if (!httpPost(L"/suggestGJStars20.php", body, response)) {
        setStatus(L"Network request failed.");
        return;
    }
    setStatus(response == L"1" ? L"Suggestion accepted." : L"Server rejected: " + response);
}

void submitDirect() {
    std::wstring level = trim(getText(gLevel));
    std::wstring account = trim(getText(gAccount));
    std::wstring gjp = trim(getText(gGjp));
    if (level.empty() || account.empty() || gjp.empty()) {
        setStatus(L"Fill Level ID, Account ID and GJP.");
        return;
    }

    std::wstring stars = std::to_wstring(comboIndex(gStars) + 1);
    std::wstring body =
        L"accountID=" + formEncode(account) +
        L"&gjp=" + formEncode(gjp) +
        L"&levelID=" + formEncode(level) +
        L"&stars=" + stars;

    setStatus(L"Applying direct rate...");
    std::wstring response;
    if (!httpPost(L"/rateGJStars20.php", body, response)) {
        setStatus(L"Network request failed.");
        return;
    }
    setStatus(response == L"1" ? L"Direct rating applied." : L"Denied: " + response);
}

void submitDemon() {
    std::wstring level = trim(getText(gLevel));
    std::wstring account = trim(getText(gAccount));
    std::wstring gjp = trim(getText(gGjp));
    if (level.empty() || account.empty() || gjp.empty()) {
        setStatus(L"Fill Level ID, Account ID and GJP.");
        return;
    }

    std::wstring rating = std::to_wstring(comboIndex(gDemon) + 1);
    std::wstring body =
        L"accountID=" + formEncode(account) +
        L"&gjp=" + formEncode(gjp) +
        L"&levelID=" + formEncode(level) +
        L"&rating=" + rating;

    setStatus(L"Applying demon difficulty...");
    std::wstring response;
    if (!httpPost(L"/rateGJDemon21.php", body, response)) {
        setStatus(L"Network request failed.");
        return;
    }
    setStatus(response != L"-1" ? L"Demon difficulty applied." : L"Denied.");
}

HWND label(HWND parent, int x, int y, int w, const wchar_t* text) {
    return CreateWindowExW(0, L"STATIC", text, WS_CHILD | WS_VISIBLE,
        x, y, w, 22, parent, nullptr, GetModuleHandleW(nullptr), nullptr);
}

HWND edit(HWND parent, int x, int y, int w, const wchar_t* placeholder) {
    HWND h = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"", WS_CHILD | WS_VISIBLE | ES_AUTOHSCROLL,
        x, y, w, 24, parent, nullptr, GetModuleHandleW(nullptr), nullptr);
    SetWindowTextW(h, placeholder);
    return h;
}

HWND combo(HWND parent, int x, int y, int w) {
    return CreateWindowExW(0, L"COMBOBOX", L"", WS_CHILD | WS_VISIBLE | CBS_DROPDOWNLIST,
        x, y, w, 180, parent, nullptr, GetModuleHandleW(nullptr), nullptr);
}

void addComboItems(HWND c, const std::vector<std::wstring>& items, int selected = 0) {
    for (const auto& item : items) SendMessageW(c, CB_ADDSTRING, 0, reinterpret_cast<LPARAM>(item.c_str()));
    SendMessageW(c, CB_SETCURSEL, selected, 0);
}

LRESULT CALLBACK WndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp) {
    switch (msg) {
    case WM_CREATE: {
        label(hwnd, 12, 10, 120, L"Level ID");
        gLevel = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"", WS_CHILD | WS_VISIBLE | ES_AUTOHSCROLL,
            130, 8, 150, 24, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        label(hwnd, 12, 44, 120, L"Account ID");
        gAccount = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"", WS_CHILD | WS_VISIBLE | ES_AUTOHSCROLL,
            130, 42, 150, 24, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        label(hwnd, 12, 78, 120, L"GJP");
        gGjp = CreateWindowExW(WS_EX_CLIENTEDGE, L"EDIT", L"", WS_CHILD | WS_VISIBLE | ES_AUTOHSCROLL | ES_PASSWORD,
            130, 76, 260, 24, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        label(hwnd, 12, 112, 120, L"Stars");
        gStars = combo(hwnd, 130, 110, 150);
        {
            std::vector<std::wstring> stars;
            for (int i = 1; i <= 10; ++i) stars.push_back(std::to_wstring(i) + L"★");
            addComboItems(gStars, stars, 4);
        }

        label(hwnd, 12, 146, 120, L"Feature");
        gFeature = combo(hwnd, 130, 144, 180);
        addComboItems(gFeature, {
            L"None", L"Featured", L"Epic", L"Legendary", L"Mythic"
        });

        label(hwnd, 12, 180, 120, L"Demon");
        gDemon = combo(hwnd, 130, 178, 180);
        addComboItems(gDemon, {
            L"Easy", L"Medium", L"Hard", L"Insane",
            L"Extreme", L"Insane+", L"Extreme+", L"Legacy"
        });

        gCoins = CreateWindowExW(0, L"BUTTON", L"Coins verified",
            WS_CHILD | WS_VISIBLE | BS_AUTOCHECKBOX,
            12, 214, 160, 24, hwnd,
            reinterpret_cast<HMENU>(1001), GetModuleHandleW(nullptr), nullptr);

        CreateWindowExW(0, L"BUTTON", L"Suggest",
            WS_CHILD | WS_VISIBLE | BS_DEFPUSHBUTTON,
            185, 212, 95, 30, hwnd,
            reinterpret_cast<HMENU>(1002), GetModuleHandleW(nullptr), nullptr);

        CreateWindowExW(0, L"BUTTON", L"Direct Rate",
            WS_CHILD | WS_VISIBLE,
            285, 212, 105, 30, hwnd,
            reinterpret_cast<HMENU>(1003), GetModuleHandleW(nullptr), nullptr);

        CreateWindowExW(0, L"BUTTON", L"Demon",
            WS_CHILD | WS_VISIBLE,
            395, 212, 85, 30, hwnd,
            reinterpret_cast<HMENU>(1004), GetModuleHandleW(nullptr), nullptr);

        gStatus = CreateWindowExW(WS_EX_CLIENTEDGE, L"STATIC", L"Ready.",
            WS_CHILD | WS_VISIBLE | SS_LEFT,
            12, 254, 468, 42, hwnd, nullptr, GetModuleHandleW(nullptr), nullptr);

        return 0;
    }
    case WM_COMMAND:
        switch (LOWORD(wp)) {
        case 1002: submitSuggest(); return 0;
        case 1003: submitDirect(); return 0;
        case 1004: submitDemon(); return 0;
        default: break;
        }
        break;
    case WM_HOTKEY:
        if (wp == HOTKEY_ID) {
            ShowWindow(hwnd, IsWindowVisible(hwnd) ? SW_HIDE : SW_SHOW);
            if (IsWindowVisible(hwnd)) SetForegroundWindow(hwnd);
            return 0;
        }
        break;
    case WM_CLOSE:
        ShowWindow(hwnd, SW_HIDE);
        return 0;
    case WM_DESTROY:
        UnregisterHotKey(hwnd, HOTKEY_ID);
        PostQuitMessage(0);
        return 0;
    default:
        break;
    }
    return DefWindowProcW(hwnd, msg, wp, lp);
}

DWORD WINAPI UiThread(LPVOID) {
    WNDCLASSW wc{};
    wc.lpfnWndProc = WndProc;
    wc.hInstance = GetModuleHandleW(nullptr);
    wc.lpszClassName = kClassName;
    wc.hCursor = LoadCursorW(nullptr, IDC_ARROW);
    wc.hbrBackground = reinterpret_cast<HBRUSH>(COLOR_BTNFACE + 1);

    RegisterClassW(&wc);

    gWnd = CreateWindowExW(
        WS_EX_TOOLWINDOW,
        kClassName,
        kTitle,
        WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU,
        120, 120, 510, 340,
        nullptr, nullptr, wc.hInstance, nullptr
    );
    if (!gWnd) return 0;

    RegisterHotKey(gWnd, HOTKEY_ID, MOD_CONTROL | MOD_SHIFT, 'R');
    ShowWindow(gWnd, SW_HIDE);
    UpdateWindow(gWnd);

    MSG msg{};
    while (GetMessageW(&msg, nullptr, 0, 0) > 0) {
        TranslateMessage(&msg);
        DispatchMessageW(&msg);
    }
    return 0;
}
}

extern "C" __declspec(dllexport) const char* MuchoModeratorBuild() {
    return "MuchoModerator GD20 0.1";
}

BOOL APIENTRY DllMain(HMODULE module, DWORD reason, LPVOID) {
    if (reason == DLL_PROCESS_ATTACH) {
        DisableThreadLibraryCalls(module);
        HANDLE h = CreateThread(nullptr, 0, UiThread, nullptr, 0, nullptr);
        if (h) CloseHandle(h);
    }
    return TRUE;
}
