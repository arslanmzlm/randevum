export type MapPoint = { lat: number; lng: number };

/** Where the picker opens when no point is set, and the zoom it snaps to once one is. */
export type MapDefaults = {
    lat: number;
    lng: number;
    zoom: number;
    selected_zoom: number;
};
