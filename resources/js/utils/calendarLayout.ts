// Pure geometry for the calendar time grid. The server sends clinic-local wall-clock strings
// ('YYYY-MM-DD HH:mm'), already converted to the clinic timezone — so positioning is a plain
// minute-of-day calculation here, no tz math. Kept framework-free for unit testing.

/** A time block placed within a column's [fromMin, toMin] window, with overlap-split lanes. */
export type PositionedBlock<T> = {
    item: T;
    startMin: number;
    endMin: number;
    /** Top offset as a percentage of the column window (0–100). */
    topPct: number;
    /** Height as a percentage of the column window. */
    heightPct: number;
    /** 0-based lane within the overlapping cluster. */
    lane: number;
    /** Total concurrent lanes in this block's cluster (column width = 1/lanes). */
    lanes: number;
};

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/** Minute-of-day for the clinic-local wall-clock part of a 'YYYY-MM-DD HH:mm[:ss]' string. */
export function wallClockMinutes(value: string): number {
    return Number(value.slice(11, 13)) * 60 + Number(value.slice(14, 16));
}

/** The date part ('YYYY-MM-DD') of a wall-clock string. */
export function wallClockDate(value: string): string {
    return value.slice(0, 10);
}

/** 'HH:mm' label for a minute-of-day. */
export function minutesToLabel(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

/** Whole-hour boundaries (in minutes) spanning [fromMin, toMin] inclusive, for the grid lines. */
export function hourMarks(fromMin: number, toMin: number): number[] {
    const start = Math.floor(fromMin / 60);
    const end = Math.ceil(toMin / 60);
    const marks: number[] = [];

    for (let h = start; h <= end; h += 1) {
        marks.push(h * 60);
    }

    return marks;
}

/**
 * Position items within [fromMin, toMin] and split overlapping ones into side-by-side lanes.
 * Greedy interval colouring: items are grouped into clusters of transitively-overlapping blocks,
 * and every block in a cluster shares the cluster's lane count so equal-width columns line up.
 */
export function layoutBlocks<T>(
    items: T[],
    getRange: (item: T) => { startMin: number; endMin: number },
    fromMin: number,
    toMin: number,
): PositionedBlock<T>[] {
    const span = Math.max(1, toMin - fromMin);

    const ranged = items
        .map((item) => {
            const { startMin, endMin } = getRange(item);

            return {
                item,
                startMin: clamp(startMin, fromMin, toMin),
                endMin: clamp(Math.max(endMin, startMin), fromMin, toMin),
                lane: 0,
            };
        })
        .sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin);

    const result: PositionedBlock<T>[] = [];
    let cluster: (typeof ranged)[number][] = [];
    let clusterEnd = -Infinity;

    const flush = (): void => {
        const laneEnds: number[] = [];

        for (const block of cluster) {
            let lane = laneEnds.findIndex((end) => end <= block.startMin);

            if (lane === -1) {
                lane = laneEnds.length;
                laneEnds.push(block.endMin);
            } else {
                laneEnds[lane] = block.endMin;
            }

            block.lane = lane;
        }

        for (const block of cluster) {
            result.push({
                item: block.item,
                startMin: block.startMin,
                endMin: block.endMin,
                topPct: ((block.startMin - fromMin) / span) * 100,
                heightPct: ((block.endMin - block.startMin) / span) * 100,
                lane: block.lane,
                lanes: laneEnds.length,
            });
        }

        cluster = [];
    };

    for (const block of ranged) {
        if (cluster.length > 0 && block.startMin >= clusterEnd) {
            flush();
            clusterEnd = -Infinity;
        }

        cluster.push(block);
        clusterEnd = Math.max(clusterEnd, block.endMin);
    }

    if (cluster.length > 0) {
        flush();
    }

    return result;
}
