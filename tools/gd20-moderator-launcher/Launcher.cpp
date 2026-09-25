#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <string>
#include <vector>

namespace {
std::wstring moduleDir() {
    wchar_t buf[MAX_PATH]{};
    DWORD n = GetModuleFileNameW(nullptr, buf, MAX_PATH);
    if (!n || n >= MAX_PATH) return L".";
    std::wstring path(buf, n);
    const auto pos = path.find_last_of(L"\\/");
    return pos == std::wstring::npos ? L"." : path.substr(0, pos);
}

bool injectDll(HANDLE process, const std::wstring& dllPath) {
    const SIZE_T bytes = (dllPath.size() + 1) * sizeof(wchar_t);
    void* remote = VirtualAllocEx(process, nullptr, bytes, MEM_COMMIT | MEM_RESERVE, PAGE_READWRITE);
    if (!remote) return false;

    bool ok = false;
    if (WriteProcessMemory(process, remote, dllPath.c_str(), bytes, nullptr)) {
        HMODULE kernel32 = GetModuleHandleW(L"kernel32.dll");
        auto loadLibraryW = reinterpret_cast<LPTHREAD_START_ROUTINE>(
            GetProcAddress(kernel32, "LoadLibraryW")
        );
        if (loadLibraryW) {
            HANDLE thread = CreateRemoteThread(process, nullptr, 0, loadLibraryW, remote, 0, nullptr);
            if (thread) {
                WaitForSingleObject(thread, 10000);
                DWORD code = 0;
                if (GetExitCodeThread(thread, &code)) ok = code != 0;
                CloseHandle(thread);
            }
        }
    }

    VirtualFreeEx(process, remote, 0, MEM_RELEASE);
    return ok;
}

bool launchGame(const std::wstring& gamePath, const std::wstring& dllPath) {
    STARTUPINFOW si{};
    si.cb = sizeof(si);
    PROCESS_INFORMATION pi{};

    std::vector<wchar_t> command(gamePath.begin(), gamePath.end());
    command.push_back(L'\0');

    if (!CreateProcessW(
        gamePath.c_str(),
        command.data(),
        nullptr, nullptr, FALSE,
        CREATE_SUSPENDED,
        nullptr, moduleDir().c_str(), &si, &pi
    )) return false;

    bool injected = injectDll(pi.hProcess, dllPath);
    ResumeThread(pi.hThread);

    CloseHandle(pi.hThread);
    CloseHandle(pi.hProcess);
    return injected;
}
}

int WINAPI wWinMain(HINSTANCE, HINSTANCE, PWSTR, int) {
    const std::wstring dir = moduleDir();
    const std::wstring game = dir + L"\\GeometryDash.exe";
    const std::wstring dll = dir + L"\\MuchoModerator.dll";

    if (GetFileAttributesW(game.c_str()) == INVALID_FILE_ATTRIBUTES) {
        MessageBoxW(nullptr, L"GeometryDash.exe was not found next to this launcher.", L"Mucho Moderator GD 2.0", MB_ICONERROR);
        return 2;
    }
    if (GetFileAttributesW(dll.c_str()) == INVALID_FILE_ATTRIBUTES) {
        MessageBoxW(nullptr, L"MuchoModerator.dll was not found next to this launcher.", L"Mucho Moderator GD 2.0", MB_ICONERROR);
        return 3;
    }

    if (!launchGame(game, dll)) {
        MessageBoxW(nullptr,
            L"Could not load MuchoModerator.dll into GeometryDash.exe.\n"
            L"Make sure both files are from the same package and run the launcher normally.",
            L"Mucho Moderator GD 2.0", MB_ICONERROR);
        return 4;
    }

    return 0;
}
