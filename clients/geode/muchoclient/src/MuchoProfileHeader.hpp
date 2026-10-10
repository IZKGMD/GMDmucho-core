#pragma once
#include "MuchoProfilePopup.hpp"

namespace mucho::profile {
    class Header final : public CCNode {
        std::string m_origin;
        int m_account = 0;
        Artwork *m_avatar = nullptr, *m_banner = nullptr;
        CCLayerColor* m_avatarFrame = nullptr;
        async::TaskHolder<web::WebResponse> m_request;
    public:
        void saved(matjson::Value const& player) { m_request.cancel(); apply(player); }
        void apply(matjson::Value const& player) {
            if (player["account_id"].asInt().unwrapOr(0) != m_account || origin() != m_origin) return;
            auto avatar = version(player,"avatar"), banner = version(player,"banner");
            m_avatarFrame->setVisible(validVersion(avatar));
            m_avatar->setVisible(validVersion(avatar)); m_banner->setVisible(validVersion(banner));
            m_avatar->stored(m_origin,m_account,"avatar",avatar); m_banner->stored(m_origin,m_account,"banner",banner);
        }
        void load() {
            m_request.spawn("MuchoCore profile header", web::WebRequest().timeout(std::chrono::seconds(8)).followRedirects(false)
                .header("Content-Type","application/x-www-form-urlencoded").bodyString("accountID=" + std::to_string(m_account))
                .post(m_origin + "/api/profile/get"), [this](web::WebResponse response) {
                    if (origin() != m_origin || !response.ok() || response.string().unwrapOr("").size() > 16384) return;
                    auto json = response.json();
                    if (json && json.unwrap()["ok"].asBool().unwrapOr(false)) apply(json.unwrap()["data"]);
                });
        }
        static Header* create(std::string url,int account,CCSize size);
    };
}
