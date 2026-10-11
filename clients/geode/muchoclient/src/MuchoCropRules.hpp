#pragma once
#include <algorithm>
#include <cmath>

namespace mucho::profile {
    struct CropRegion { double width, height, left, top, imageWidth, imageHeight; };
    // Same pixel rounding and top-left origin as ProfileImage::render on the server.
    inline CropRegion cropRegion(double w,double h,int x,int y,int zoom,int rotation,double outW=960,double outH=320) {
        if (rotation == 90 || rotation == 270) std::swap(w,h);
        auto scale = std::max(outW/w,outH/h)*(std::clamp(zoom,100,300)/100.0);
        double cw = std::min(w,std::round(outW/scale)), ch = std::min(h,std::round(outH/scale));
        return {cw,ch,std::round((w-cw)*(std::clamp(x,0,1000)/1000.0)),
            std::round((h-ch)*(std::clamp(y,0,1000)/1000.0)),w,h};
    }
    inline int cropPosition(double position,double slack) {
        return slack <= 0 ? 500 : static_cast<int>(std::round(std::clamp(position/slack,0.0,1.0)*1000));
    }
    inline int cropZoom(double baseWidth,double requestedWidth) {
        return static_cast<int>(std::round(std::clamp(baseWidth/std::max(1e-9,requestedWidth)*100,100.0,300.0)));
    }
}
