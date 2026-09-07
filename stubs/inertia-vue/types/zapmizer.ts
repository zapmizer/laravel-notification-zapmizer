/** Espelho dos payloads JSON do `ConnectController` do pacote zapmizer. */

export interface ZapmizerInstanceConnection {
  id: number;
  state: string;
  state_label: string;
  is_online: boolean;
  is_up: boolean;
  qrcode: string | null;
  qrcode_available_at: string | null;
  qrcode_expires_at: string | null;
  number: string | null;
}

export interface ZapmizerInstance {
  id: number;
  number: string;
  is_current: boolean;
}

export interface ZapmizerConnection {
  id: number;
  zapmizer_team_id: number | null;
  zapmizer_team_name: string | null;
  phone_number: string | null;
  bot_instance_id: number | null;
  connected_at: string | null;
  webhook_id: number | null;
  is_active: boolean;
  api_token_masked: string | null;
  created_at: string;
  updated_at: string;
}
