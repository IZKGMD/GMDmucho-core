#pragma once
#include <functional>
#include <string>
namespace mucho::media {
void compose(std::string scope,int target,std::string caption="",std::string subject="",std::function<void()> accepted={});
void showAttachments(int message);
void showChat(int room);
void showMine();
}
