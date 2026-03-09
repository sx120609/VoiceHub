<template>
  <div class="register-form">
    <div class="form-header">
      <h2>创建账号</h2>
      <p>注册后即可登录，QQ邮箱将直接绑定并生效</p>
    </div>

    <form :class="['auth-form', { 'has-error': !!error }]" @submit.prevent="handleRegister">
      <div class="form-group">
        <label for="name">姓名</label>
        <div class="input-wrapper">
          <input
            id="name"
            v-model="form.name"
            :class="{ 'input-error': error }"
            placeholder="请输入姓名"
            required
            type="text"
            @input="error = ''"
          >
        </div>
      </div>

      <div class="form-group">
        <label for="username">用户名</label>
        <div class="input-wrapper">
          <input
            id="username"
            v-model="form.username"
            :class="{ 'input-error': error }"
            placeholder="3-32位，建议使用学号"
            required
            type="text"
            @input="error = ''"
          >
        </div>
      </div>

      <div class="form-group">
        <label for="email">QQ邮箱</label>
        <div class="input-wrapper">
          <input
            id="email"
            v-model="form.email"
            :class="{ 'input-error': error }"
            placeholder="例如：123456789@qq.com"
            required
            type="email"
            @input="error = ''"
          >
        </div>
      </div>

      <div class="form-group">
        <label for="password">密码</label>
        <div class="input-wrapper">
          <input
            id="password"
            v-model="form.password"
            :class="{ 'input-error': error }"
            :type="showPassword ? 'text' : 'password'"
            placeholder="至少6位"
            required
            @input="error = ''"
          >
          <button class="password-toggle" type="button" @click="showPassword = !showPassword">
            {{ showPassword ? '隐藏' : '显示' }}
          </button>
        </div>
      </div>

      <div class="form-group">
        <label for="confirmPassword">确认密码</label>
        <div class="input-wrapper">
          <input
            id="confirmPassword"
            v-model="form.confirmPassword"
            :class="{ 'input-error': error }"
            :type="showConfirmPassword ? 'text' : 'password'"
            placeholder="请再次输入密码"
            required
            @input="error = ''"
          >
          <button
            class="password-toggle"
            type="button"
            @click="showConfirmPassword = !showConfirmPassword"
          >
            {{ showConfirmPassword ? '隐藏' : '显示' }}
          </button>
        </div>
      </div>

      <div v-if="error" class="error-container">
        <span class="error-message">{{ error }}</span>
      </div>

      <button :disabled="loading" class="submit-btn" type="submit">
        <span v-if="loading">注册中...</span>
        <span v-else>注册并登录</span>
      </button>
    </form>

    <div class="form-footer">
      <p class="help-text">已有账号？<NuxtLink to="/login">去登录</NuxtLink></p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useAuth } from '~/composables/useAuth'

const auth = useAuth()
const route = useRoute()

const form = ref({
  name: '',
  username: '',
  email: '',
  password: '',
  confirmPassword: ''
})

const loading = ref(false)
const error = ref('')
const showPassword = ref(false)
const showConfirmPassword = ref(false)

const qqEmailRegex = /^[1-9]\d{4,10}@qq\.com$/i

const handleRegister = async () => {
  if (!form.value.name || !form.value.username || !form.value.email || !form.value.password) {
    error.value = '请完整填写注册信息'
    return
  }

  if (!qqEmailRegex.test(form.value.email.trim().toLowerCase())) {
    error.value = '仅支持QQ邮箱（@qq.com）'
    return
  }

  if (form.value.password.length < 6) {
    error.value = '密码长度不能少于6位'
    return
  }

  if (form.value.password !== form.value.confirmPassword) {
    error.value = '两次输入的密码不一致'
    return
  }

  loading.value = true
  error.value = ''

  try {
    await $fetch('/api/auth/register', {
      method: 'POST',
      body: {
        name: form.value.name.trim(),
        username: form.value.username.trim(),
        email: form.value.email.trim().toLowerCase(),
        password: form.value.password
      }
    })

    await auth.initAuth()
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await navigateTo(redirect)
  } catch (err: any) {
    error.value = err?.data?.message || err?.message || '注册失败，请稍后重试'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.register-form {
  width: 100%;
  max-width: 420px;
}

.form-header {
  text-align: center;
  margin-bottom: 24px;
}

.form-header h2 {
  color: var(--text-primary);
  font-size: 28px;
  margin: 0 0 8px;
  font-weight: 700;
}

.form-header p {
  color: var(--text-secondary);
  margin: 0;
  font-size: 14px;
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-group label {
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 600;
}

.input-wrapper {
  position: relative;
}

.input-wrapper input {
  width: 100%;
  padding: 12px 14px;
  background: var(--bg-tertiary);
  border: 1px solid var(--border-secondary);
  border-radius: 10px;
  color: var(--text-primary);
  font-size: 14px;
  transition: border-color 0.2s;
}

.input-wrapper input:focus {
  outline: none;
  border-color: var(--accent-primary, #4f8cff);
}

.input-wrapper input.input-error {
  border-color: #ef4444;
}

.password-toggle {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  border: none;
  background: transparent;
  color: var(--text-secondary);
  font-size: 12px;
  cursor: pointer;
  padding: 2px 4px;
}

.error-container {
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  border-radius: 10px;
  padding: 10px 12px;
}

.error-message {
  color: #ef4444;
  font-size: 13px;
}

.submit-btn {
  width: 100%;
  padding: 12px 14px;
  border: none;
  border-radius: 10px;
  background: #2563eb;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}

.submit-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.form-footer {
  margin-top: 18px;
  text-align: center;
}

.help-text {
  font-size: 13px;
  color: var(--text-secondary);
}

.help-text a {
  color: #60a5fa;
  text-decoration: none;
  font-weight: 600;
}

.help-text a:hover {
  text-decoration: underline;
}
</style>
