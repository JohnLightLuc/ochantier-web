import type { CSSProperties } from 'react';

type Props = {
    height: string | number;
    radius?: string;
    style?: CSSProperties;
};

/** Bloc de chargement animé. */
export default function Skeleton({ height, radius = 'var(--radius-md)', style }: Props) {
    return <div className="och-skeleton" style={{ height, borderRadius: radius, ...style }} />;
}
