import type { CSSProperties } from 'react';

type Props = {
    name: string;
    size?: string;
    color?: string;
    /** Icône en contour (FILL 0) plutôt que pleine. */
    outlined?: boolean;
    style?: CSSProperties;
};

/** Icône Material Symbols Rounded. */
export default function Icon({ name, size = 'var(--icon-sm)', color, outlined, style }: Props) {
    return (
        <span
            className="material-symbols-rounded"
            aria-hidden="true"
            style={{ fontSize: size, color, ...(outlined ? { fontVariationSettings: "'FILL' 0" } : null), ...style }}
        >
            {name}
        </span>
    );
}
