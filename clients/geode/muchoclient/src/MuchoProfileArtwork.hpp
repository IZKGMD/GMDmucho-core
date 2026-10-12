#pragma once
#include <Geode/Geode.hpp>
#include <Geode/ui/LazySprite.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>
#include "MuchoAppearance.hpp"
#include "MuchoProfileRules.hpp"
#include "MuchoCropRules.hpp"
#include "MuchoProfileAssets.hpp"
#include "MuchoMotion.hpp"
#include <chrono>
#include <cmath>
#include <functional>

using namespace geode::prelude;

namespace mucho::profile {
    inline std::string origin() {
        auto url = Mod::get()->getSettingValue<std::string>("server-url");
        while (!url.empty() && url.back() == '/') url.pop_back();
        return url;
    }
    void clearCache();

    class Artwork final : public CCNode {
        LazySprite* m_image = nullptr;
        CCScale9Sprite* m_background = nullptr;
        CCLabelBMFont* m_hint = nullptr;
        CCSize m_box;
        std::string m_origin, m_key;
        unsigned m_generation = 0;
        bool m_ready = false, m_loading = false;
        int m_x = 500, m_y = 500, m_zoom = 100, m_rotation = 0;
        GLubyte m_dim = 255;
        std::function<void(CCTexture2D*)> m_loaded;

        void reset() {
            ++m_generation; m_ready = false; m_loading = false; m_key.clear();
            if (m_image) m_image->removeFromParent();
            m_image = LazySprite::create(m_box, false);
            m_image->setOpacity(m_dim);
            m_image->setVisible(false); addChild(m_image, 1);
            m_hint->setVisible(true);
        }
        void decode(std::vector<uint8_t> bytes, CCImage::EImageFormat format) {
            m_loading = true;
            auto weak = WeakRef(this); auto generation = m_generation; auto image = m_image;
            // Keep the decoder alive until completion, even when this view closes.
            // Cancelling LazySprite mid-decode in Geode 5.10 can abandon its CCImage.
            image->retain();
            image->setLoadCallback([weak, generation, image](Result<> result) {
                geode::queueInMainThread([image] { image->release(); });
                auto self = weak.lock();
                if (!self || generation != self->m_generation) return;
                self->m_loading = false;
                if (!result) { self->m_hint->setString("Image unavailable"); return; }
                self->m_ready = true; self->m_hint->setVisible(false); self->fit();
                mucho::motion::appear(self->m_image);
                if (self->m_loaded) self->m_loaded(image->getTexture());
            });
            image->loadFromData(std::move(bytes),format);
        }
        void fit() {
            if (!m_ready) return;
            auto size = m_image->getTexture()->getContentSize();
            auto pixels = m_image->getTexture()->getContentSizeInPixels();
            float w = pixels.width, h = pixels.height;
            bool quarter = m_rotation == 90 || m_rotation == 270;
            auto region = cropRegion(w,h,m_x,m_y,m_zoom,m_rotation,m_box.width,m_box.height);
            float cw = static_cast<float>(region.width), ch = static_cast<float>(region.height);
            float left = static_cast<float>(region.left), top = static_cast<float>(region.top);
            CCRect rect{left, top, cw, ch};
            if (m_rotation == 90) rect = {top, h - left - cw, ch, cw};
            if (m_rotation == 180) rect = {w - left - cw, h - top - ch, cw, ch};
            if (m_rotation == 270) rect = {w - top - ch, left, ch, cw};
            float factor = size.width / w;
            rect = {rect.origin.x*factor,rect.origin.y*factor,rect.size.width*factor,rect.size.height*factor};
            m_image->setTextureRect(rect); m_image->setRotation(static_cast<float>(m_rotation));
            // Integer crop pixels are resampled into the exact output box on
            // the server too. Avoid gaps caused by uniform-scale rounding.
            m_image->setScaleX((quarter?m_box.height:m_box.width)/rect.size.width);
            m_image->setScaleY((quarter?m_box.width:m_box.height)/rect.size.height);
            m_image->setPosition(m_box / 2);
            m_image->setOpacity(m_dim);
            m_image->setVisible(true);
        }
    public:
        bool ready() const { return m_ready; }
        void onLoaded(std::function<void(CCTexture2D*)> loaded) { m_loaded=std::move(loaded); }
        bool loading() const { return m_loading; }
        void suspend() { ++m_generation; m_loading = false; }
        void dim(GLubyte opacity) { m_dim = opacity; if (m_image) m_image->setOpacity(opacity); }
        void crop(int x, int y, int zoom, int rotation) {
            m_x = x; m_y = y; m_zoom = zoom; m_rotation = rotation; fit();
        }
        void empty() {
            reset(); m_hint->setString(m_box.width == m_box.height ? "Avatar" : "Cover");
        }
        void selected(std::vector<uint8_t> const& bytes, bool png) {
            reset(); m_x = m_y = 500; m_zoom = 100; m_rotation = 0;
            decode(bytes, png ? CCImage::kFmtPng : CCImage::kFmtJpg);
        }
        void stored(std::string url, int account, std::string kind, std::string version) {
            std::string key = url + "/" + std::to_string(account) + "/" + kind + "/" + version;
            if (key == m_key && (m_ready || m_loading)) {
                if (m_ready && m_loaded) m_loaded(m_image->getTexture());
                return;
            }
            empty(); m_origin = std::move(url); m_x = m_y = 500; m_zoom = 100; m_rotation = 0;
            if (!appearance::validOrigin(m_origin) || !validVersion(version) || account <= 0 || (kind != "avatar" && kind != "banner")) return;
            m_key = std::move(key); m_loading = true;
            auto weak = WeakRef(this); auto generation = m_generation;
            if (!storedTexture(m_origin,account,std::move(kind),std::move(version),
                [weak,generation] { auto self = weak.lock(); return self && generation == self->m_generation && origin() == self->m_origin; },
                [weak,generation](CCTexture2D* texture) {
                    auto self = weak.lock(); if (!self || generation != self->m_generation) return;
                    self->m_loading = false;
                    if (!texture || !self->m_image->CCSprite::initWithTexture(texture)) { self->m_hint->setString("Image unavailable"); return; }
                    self->m_ready = true; self->m_hint->setVisible(false); self->fit();
                    mucho::motion::appear(self->m_image);
                    if (self->m_loaded) self->m_loaded(texture);
                })) { m_loading = false; m_hint->setString("Image queue busy"); }
        }
        static Artwork* create(CCSize size) {
            auto node = new Artwork();
            if (!node->init()) { delete node; return nullptr; }
            node->m_box = size; node->setContentSize(size);
            node->m_background = CCScale9Sprite::create("square02_small.png");
            node->m_background->setContentSize(size); node->m_background->setPosition(size / 2);
            node->m_background->setColor({27,41,65}); node->addChild(node->m_background);
            node->m_hint = CCLabelBMFont::create(size.width == size.height ? "Avatar" : "Cover", "bigFont.fnt");
            node->m_hint->limitLabelWidth(size.width - 8, .3f, 0.f); node->m_hint->setPosition(size / 2);
            node->m_hint->setOpacity(140); node->addChild(node->m_hint, 2);
            node->empty(); node->autorelease(); return node;
        }
    };
}

