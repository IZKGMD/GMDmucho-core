#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <wincrypt.h>
#include <wchar.h>
#include "pins.h"
static WCHAR folder[32768],path[32768];
static void stop(LPCWSTR text){MessageBoxW(0,text,L"MuchoCore — требуется восстановление",MB_OK|MB_ICONERROR);ExitProcess(41);}
static void locate(void){DWORD n=GetModuleFileNameW(0,folder,32768);if(!n||n>=32768)stop(L"Не удалось определить папку игры.");while(n&&folder[n-1]!=L'\\')--n;folder[n]=0;}
static void make_path(LPCWSTR name){if(wcslen(folder)+wcslen(name)>=32768)stop(L"Слишком длинный путь.");wcscpy_s(path,32768,folder);wcscat_s(path,32768,name);}
static int hash_ok(LPCWSTR name,const char* expected){
 make_path(name);HANDLE file=CreateFileW(path,GENERIC_READ,FILE_SHARE_READ,0,OPEN_EXISTING,FILE_ATTRIBUTE_NORMAL,0);if(file==INVALID_HANDLE_VALUE)return 0;
 HCRYPTPROV ctx=0;HCRYPTHASH hash=0;int ok=CryptAcquireContextW(&ctx,0,0,PROV_RSA_AES,CRYPT_VERIFYCONTEXT)&&CryptCreateHash(ctx,CALG_SHA_256,0,0,&hash);
 BYTE buffer[65536],digest[32];DWORD n=0,size=32;
 while(ok){if(!ReadFile(file,buffer,sizeof buffer,&n,0)){ok=0;break;}if(!n)break;ok=CryptHashData(hash,buffer,n,0);}
 ok=ok&&CryptGetHashParam(hash,HP_HASHVAL,digest,&size,0)&&size==32;CloseHandle(file);if(hash)CryptDestroyHash(hash);if(ctx)CryptReleaseContext(ctx,0);
 if(!ok)return 0;const char* digits="0123456789abcdef";for(int i=0;i<32;i++)if(expected[i*2]!=digits[digest[i]>>4]||expected[i*2+1]!=digits[digest[i]&15])return 0;return 1;
}
__declspec(dllexport) void MuchoBootstrapStart(void){
 locate();if(!hash_ok(L"Geode.dll",MUCHO_LOADER_SHA)||!hash_ok(L"geode\\mods\\izkgmd.muchoclient.geode",MUCHO_CLIENT_SHA))
  stop(L"Обязательные файлы MuchoGeode или MuchoClient отсутствуют либо повреждены. Открой MuchoLauncher и нажми «Восстановить». Игра не будет запущена.");
 make_path(L"Geode.dll");if(!LoadLibraryExW(path,0,LOAD_LIBRARY_SEARCH_DLL_LOAD_DIR|LOAD_LIBRARY_SEARCH_DEFAULT_DIRS))
  stop(L"MuchoGeode не загрузился. Восстанови файлы через MuchoLauncher и проверь Microsoft Visual C++ Runtime.");
}
__declspec(dllexport) void MuchoClientRequire(void){
 HMODULE module=GetModuleHandleW(L"izkgmd.muchoclient.dll");
 typedef int(*Health)(void);Health health=module?(Health)GetProcAddress(module,"MuchoClientHealth_v1"):0;
 if(!health||health()!=1)stop(L"MuchoClient не работает или отключён. Верни его в Geode и запусти игру через MuchoLauncher. Без MuchoClient игровое меню недоступно.");
}
BOOL WINAPI DllMain(HINSTANCE instance,DWORD reason,LPVOID reserved){(void)instance;(void)reason;(void)reserved;return TRUE;}
