#include "MuchoProfileArtwork.hpp"
#include "MuchoProfileAssets.hpp"
#include "MuchoDeferred.hpp"
#include "MuchoPerformanceRules.hpp"
#include <memory>

namespace mucho::profile {
namespace {
    using Clock = std::chrono::steady_clock;
    struct ProfileParams { std::string url; int account; };
    struct ImageParams { std::string url,kind,version; int account; };
    struct ProfileEntry { matjson::Value player; Clock::time_point expires; };
    using Texture = Ref<CCTexture2D>;
    performance::SharedRequests<std::string,ProfileParams,matjson::Value> profiles;
    performance::SharedRequests<std::string,ImageParams,Texture> pictures;
    performance::WeightedCache<std::string,ProfileEntry> metadata{2*1024*1024,128};
    performance::WeightedCache<std::string,Texture> textures{8*1024*1024,32};
    std::map<std::string,std::shared_ptr<async::TaskHolder<web::WebResponse>>> profileTasks,imageTasks;
    std::string cacheOrigin;
    bool profilePumpQueued = false, picturePumpQueued = false;
    void sync(std::string const& url) {
        if (cacheOrigin == url) return;
        metadata.clear(); textures.clear(); cacheOrigin = url;
    }
    void pumpProfiles();
    void pumpPictures();
    void finishProfile(std::string const& key,matjson::Value player) {
        profileTasks.erase(key); profiles.complete(key,std::move(player)); pumpProfiles();
    }
    void finishPicture(std::string const& key,Texture texture) {
        imageTasks.erase(key); pictures.complete(key,std::move(texture)); pumpPictures();
    }
    void pumpProfiles() {
        while (auto next = profiles.take()) {
            auto [key,params] = *next;
            auto task = std::make_shared<async::TaskHolder<web::WebResponse>>(); profileTasks[key] = task;
            task->spawn("MuchoCore shared profile",web::WebRequest().timeout(std::chrono::seconds(8))
                .followRedirects(false).header("Content-Type","application/x-www-form-urlencoded")
                .bodyString("accountID="+std::to_string(params.account)).post(params.url+"/api/profile/get"),
                [key,params](web::WebResponse response) {
                    if (!performance::defer([] { return true; },[key,params,response = std::move(response)] {
                        matjson::Value player;
                        if (profiles.live(key) && origin() == params.url && response.ok() && response.data().size() <= 16384) {
                            auto json = response.json();
                            if (json && json.unwrap()["ok"].asBool().unwrapOr(false) &&
                                json.unwrap()["data"]["account_id"].asInt().unwrapOr(0) == params.account) {
                                player = json.unwrap()["data"]; sync(params.url);
                                metadata.put(key,{player,Clock::now()+std::chrono::seconds(60)},response.data().size());
                            }
                        }
                        finishProfile(key,std::move(player));
                    })) { profileTasks.erase(key); profiles.discard(key); }
                });
        }
    }
    void decodePicture(std::string key,ImageParams params,std::vector<uint8_t> bytes) {
        // CPU decoding runs in the threadpool. GL upload waits for the menu.
        async::runtime().spawnBlocking<void>([key = std::move(key),params = std::move(params),bytes = std::move(bytes)] {
            auto image = std::shared_ptr<CCImage>(new CCImage,[](CCImage* value) { value->release(); });
            bool decoded = image->initWithImageData(const_cast<uint8_t*>(bytes.data()),bytes.size(),CCImage::kFmtJpg);
            Loader::get()->queueInMainThread([key,params,image = std::move(image),decoded] {
                if (!performance::defer([] { return true; },[key,params,image,decoded] {
                    Texture texture;
                    if (decoded && pictures.live(key) && origin() == params.url) {
                        texture = new CCTexture2D; texture->release();
                        if (!texture->initWithImage(image.get())) texture = nullptr;
                        if (texture) {
                            sync(params.url); auto size = texture->getContentSizeInPixels();
                            textures.put(key,texture,static_cast<size_t>(size.width)*static_cast<size_t>(size.height)*4);
                        }
                    }
                    finishPicture(key,std::move(texture));
                })) { imageTasks.erase(key); pictures.discard(key); }
            });
        });
    }
    void pumpPictures() {
        while (auto next = pictures.take()) {
            auto [key,params] = *next;
            auto task = std::make_shared<async::TaskHolder<web::WebResponse>>(); imageTasks[key] = task;
            task->spawn("MuchoCore shared profile image",web::WebRequest().timeout(std::chrono::seconds(12))
                .followRedirects(false).header("Content-Type","application/x-www-form-urlencoded")
                .bodyString("accountID="+std::to_string(params.account)+"&kind="+params.kind+"&version="+params.version)
                .post(params.url+"/api/profile/image"),[key,params](web::WebResponse response) {
                    if (!performance::defer([] { return true; },[key,params,response = std::move(response)] {
                        auto const& bytes = response.data(); auto size = imageSize(bytes);
                        if (!pictures.live(key) || origin() != params.url || !response.ok() || bytes.size() > MAX_IMAGE_BYTES ||
                            !size || size->png || size->width != (params.kind == "avatar" ? 256u : 960u) ||
                            size->height != (params.kind == "avatar" ? 256u : 320u)) { finishPicture(key,{}); return; }
                        decodePicture(key,params,bytes);
                    })) { imageTasks.erase(key); pictures.discard(key); }
                });
        }
    }
}
    void clearCache() { textures.clear(); }
    bool publicProfile(std::string url,int account,ValidAsset valid,ProfileLoaded loaded) {
        if (!appearance::validOrigin(url) || account <= 0) return false;
        sync(url); auto key = url+"/"+std::to_string(account);
        if (auto entry = metadata.get(key); entry && entry->expires > Clock::now()) {
            return performance::cached(std::move(valid),[loaded = std::move(loaded),player = entry->player] { loaded(player); });
        }
        if (!profiles.add(key,{url,account},std::move(valid),std::move(loaded))) return false;
        if (profilePumpQueued) return true;
        profilePumpQueued = true;
        if (performance::defer([] { return true; },[] { profilePumpQueued = false; pumpProfiles(); })) return true;
        profilePumpQueued = false;
        profiles.discard(key); return false;
    }
    void rememberPublicProfile(matjson::Value const& player) {
        auto account = player["account_id"].asInt().unwrapOr(0); if (account <= 0 || account > INT_MAX) return;
        auto url = origin(); sync(url);
        metadata.put(url+"/"+std::to_string(account),{player,Clock::now()+std::chrono::seconds(60)},player.dump().size());
    }
    bool storedTexture(std::string url,int account,std::string kind,std::string version,ValidAsset valid,TextureLoaded loaded) {
        sync(url); auto key = url+"/"+std::to_string(account)+"/"+kind+"/"+version;
        if (auto texture = textures.get(key)) {
            return performance::cached(std::move(valid),[loaded = std::move(loaded),texture = *texture] { loaded(texture.data()); });
        }
        if (!pictures.add(key,{url,kind,version,account},std::move(valid),[loaded = std::move(loaded)](Texture const& texture) { loaded(texture.data()); })) return false;
        if (picturePumpQueued) return true;
        picturePumpQueued = true;
        if (performance::defer([] { return true; },[] { picturePumpQueued = false; pumpPictures(); })) return true;
        picturePumpQueued = false;
        pictures.discard(key); return false;
    }
}
