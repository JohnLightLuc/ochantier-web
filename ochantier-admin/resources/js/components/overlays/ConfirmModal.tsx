import Dialog, { type DialogTone } from './Dialog';

export type ConfirmData = {
    title: string;
    body?: string;
    icon?: string;
    tone?: DialogTone;
    ok?: string;
    cancel?: string;
    onConfirm: () => void;
};

type Props = {
    modal: ConfirmData;
    onClose: () => void;
};

/** Modale de confirmation simple. */
export default function ConfirmModal({ modal, onClose }: Props) {
    return (
        <Dialog
            title={modal.title}
            body={modal.body}
            icon={modal.icon}
            tone={modal.tone}
            okLabel={modal.ok ?? 'Confirmer'}
            cancelLabel={modal.cancel}
            onClose={onClose}
            onSubmit={() => {
                onClose();
                modal.onConfirm();
            }}
        />
    );
}
