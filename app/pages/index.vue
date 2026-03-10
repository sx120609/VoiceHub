<template>
  <div class="min-h-screen bg-[#f6f8f2] text-[#1f2a1f] font-sans relative overflow-hidden flex flex-col items-center">
    <!-- Subtle background decorations -->
    <div class="fixed top-[-10%] left-[-10%] w-[50%] h-[50%] bg-[#2f7d4f]/5 blur-[120px] rounded-full pointer-events-none"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[60%] h-[60%] bg-[#c28a26]/5 blur-[150px] rounded-full pointer-events-none"></div>

    <div class="w-full max-w-6xl px-4 sm:px-6 lg:px-8 py-6 flex flex-col min-h-screen relative z-10">
      <!-- Top Bar -->
      <header class="flex items-center justify-between py-4 mb-8">
        <div class="flex items-center gap-4">
          <NuxtLink to="/" class="hover:opacity-80 transition-opacity">
            <img alt="VoiceHub Logo" class="h-10 w-auto" :src="logo" />
          </NuxtLink>
          <div v-if="schoolLogoHomeUrl && schoolLogoHomeUrl.trim()" class="hidden sm:flex items-center gap-4">
            <div class="w-px h-6 bg-zinc-300"></div>
            <img :src="proxiedSchoolLogoUrl" alt="学校Logo" class="h-8 w-auto object-contain" />
          </div>
        </div>

        <!-- User Section -->
        <div class="relative z-50">
          <ClientOnly>
            <!-- 登录后 -->
            <div v-if="isClientAuthenticated" class="flex items-center gap-4">
              <div class="hidden md:flex flex-col items-end">
                <span class="text-sm font-bold text-zinc-800">{{ user?.name || '用户' }}</span>
                <span v-if="isAdmin" class="text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700">{{ roleName }}</span>
                <span v-else class="text-xs font-medium text-zinc-500">{{ userClassInfo }}</span>
              </div>
              
              <div class="relative">
                <button @click="toggleUserActions" class="flex items-center justify-center w-10 h-10 rounded-full bg-white shadow-sm border border-zinc-200 overflow-hidden hover:shadow-md transition-shadow focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                  <img v-if="user?.avatar && !avatarError" :src="user.avatar" class="w-full h-full object-cover" @error="avatarError = true" />
                  <span v-else class="text-lg font-bold text-zinc-500">{{ user?.name?.[0] || 'U' }}</span>
                </button>

                <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 translate-y-2" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 translate-y-2">
                  <div v-if="showUserActions" class="absolute right-0 mt-3 w-48 rounded-2xl bg-white shadow-xl border border-zinc-100 py-2 user-actions-dropdown">
                    <NuxtLink to="/account" class="flex items-center gap-3 px-4 py-2.5 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                      <Icon name="user" :size="16" class="text-zinc-400" />
                      <span class="font-medium">账号管理</span>
                    </NuxtLink>
                    <NuxtLink v-if="isAdmin" to="/dashboard" class="flex items-center gap-3 px-4 py-2.5 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors">
                      <Icon name="settings" :size="16" class="text-zinc-400" />
                      <span class="font-medium">管理后台</span>
                    </NuxtLink>
                    <div class="h-px bg-zinc-100 my-1"></div>
                    <button @click="handleLogout" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors text-left">
                      <Icon name="logout" :size="16" class="text-red-400" />
                      <span class="font-medium">退出登录</span>
                    </button>
                  </div>
                </Transition>
              </div>
            </div>

            <!-- 未登录 -->
            <div v-else>
              <NuxtLink to="/login" class="flex items-center gap-2 px-5 py-2.5 rounded-full bg-[#2f7d4f] text-white font-bold shadow-sm border border-[#246a41] hover:shadow-md hover:bg-[#246a41] transition-all active:scale-95">
                <Icon name="user" :size="16" />
                <span>登录</span>
              </NuxtLink>
            </div>
          </ClientOnly>
        </div>
      </header>

      <!-- Hero Section -->
      <div v-if="siteTitle" class="text-center mb-12 animate-fade-in-up">
        <h2 class="text-4xl md:text-5xl font-black text-zinc-900 tracking-tight mb-3">
          {{ siteTitle }}
        </h2>
        <div class="w-16 h-1.5 bg-[#2f7d4f] rounded-full mx-auto mb-4"></div>
        <span class="text-zinc-500 font-medium tracking-wide">VoiceHub 校园广播系统</span>
      </div>

      <!-- Main Content Area -->
      <main class="flex-1 w-full bg-white/60 backdrop-blur-xl border border-white rounded-[2rem] shadow-xl shadow-[#2f7d4f]/5 flex flex-col overflow-hidden mb-8">
        
        <!-- Tabs Row -->
        <div class="flex overflow-x-auto hide-scrollbar border-b border-zinc-100/80 bg-white/40 px-4 sm:px-8 py-4 gap-2 sm:gap-4 relative z-20">
          <button @click="handleTabClick('schedule')" :class="['flex items-center gap-2 px-5 py-3 rounded-2xl font-bold transition-all whitespace-nowrap', activeTab === 'schedule' ? 'bg-white text-[#2f7d4f] shadow-sm border border-zinc-100' : 'text-zinc-500 hover:bg-white/60 hover:text-zinc-800']">
            <Icon name="calendar" :size="20" :class="activeTab === 'schedule' ? 'text-[#2f7d4f]' : 'text-zinc-400'" />
            <span>播出排期</span>
          </button>
          
          <button @click="handleTabClick('songs')" :class="['flex items-center gap-2 px-5 py-3 rounded-2xl font-bold transition-all whitespace-nowrap', activeTab === 'songs' ? 'bg-white text-[#2f7d4f] shadow-sm border border-zinc-100' : 'text-zinc-500 hover:bg-white/60 hover:text-zinc-800']">
            <Icon name="music" :size="20" :class="activeTab === 'songs' ? 'text-[#2f7d4f]' : 'text-zinc-400'" />
            <span>歌曲列表</span>
          </button>

          <button @click="handleTabClick('request')" :class="['flex items-center gap-2 px-5 py-3 rounded-2xl font-bold transition-all whitespace-nowrap', activeTab === 'request' ? 'bg-white text-[#2f7d4f] shadow-sm border border-zinc-100' : 'text-zinc-500 hover:bg-white/60 hover:text-zinc-800']">
            <Icon name="search" :size="20" :class="activeTab === 'request' ? 'text-[#2f7d4f]' : 'text-zinc-400'" />
            <span>投稿歌曲</span>
          </button>

          <ClientOnly>
            <button :key="notificationTabKey" ref="notificationTabRef" data-tab="notification" @click="isClientAuthenticated ? handleTabClick('notification') : showLoginNotice()" :class="['flex items-center gap-2 px-5 py-3 rounded-2xl font-bold transition-all whitespace-nowrap relative', activeTab === 'notification' ? 'bg-white text-[#2f7d4f] shadow-sm border border-zinc-100' : 'text-zinc-500 hover:bg-white/60 hover:text-zinc-800', !isClientAuthenticated && 'opacity-70']">
              <div class="relative">
                <Icon name="message-circle" :size="20" :class="activeTab === 'notification' ? 'text-[#2f7d4f]' : 'text-zinc-400'" />
                <span v-if="isClientAuthenticated && hasUnreadNotifications" class="absolute -top-1 -right-1 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white"></span>
              </div>
              <span class="flex items-center gap-1.5">
                消息
                <span v-if="isClientAuthenticated && hasUnreadNotifications" class="px-1.5 py-0.5 rounded-full bg-red-100 text-red-600 text-[10px] font-black leading-none">{{ (notificationsService?.unreadCount?.value > 99) ? '99+' : (notificationsService?.unreadCount?.value || 0) }}</span>
              </span>
            </button>
            <template #fallback>
              <button class="flex items-center gap-2 px-5 py-3 rounded-2xl font-bold text-zinc-500 opacity-70 whitespace-nowrap">
                <Icon name="message-circle" :size="20" class="text-zinc-400" />
                <span>消息</span>
              </button>
            </template>
          </ClientOnly>
        </div>

        <!-- Tab Content -->
        <div class="flex-1 overflow-hidden relative min-h-[500px] p-4 sm:p-8 bg-white/20">
          <Transition enter-active-class="transition-all duration-300 ease-out absolute inset-0 p-4 sm:p-8" enter-from-class="opacity-0 translate-y-4" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition-all duration-200 ease-in absolute inset-0 p-4 sm:p-8" leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-4">
            
            <!-- Schedule -->
            <div v-if="activeTab === 'schedule'" key="schedule" class="h-full w-full">
              <ClientOnly>
                <LazySongsScheduleList :error="error" :loading="loading" :schedules="publicSchedules" @semester-change="handleSemesterChange" />
              </ClientOnly>
            </div>

            <!-- Songs -->
            <div v-else-if="activeTab === 'songs'" key="songs" class="h-full w-full">
              <ClientOnly>
                <LazySongsSongList :error="error" :is-admin="isAdmin" :loading="loading" :songs="filteredSongs" @refresh="refreshSongs" @vote="handleVote" @withdraw="handleWithdraw" @cancel-replay="handleCancelReplay" @request-replay="handleRequestReplay" @semester-change="handleSemesterChange" />
              </ClientOnly>
            </div>

            <!-- Request -->
            <div v-else-if="activeTab === 'request'" key="request" class="h-full w-full">
              <LazySongsRequestForm ref="requestFormRef" :loading="loading" @request="handleRequest" @vote="handleVote" />
            </div>

            <!-- Notifications -->
            <div v-else-if="activeTab === 'notification'" key="notification" class="h-full w-full overflow-y-auto hide-scrollbar">
              <div v-if="!isClientAuthenticated" class="flex flex-col items-center justify-center h-full text-center p-8 bg-white rounded-3xl border border-zinc-100 shadow-sm max-w-md mx-auto my-12">
                <div class="w-16 h-16 bg-zinc-50 rounded-full flex items-center justify-center text-3xl mb-4 shadow-inner ring-1 ring-zinc-100">🔒</div>
                <h3 class="text-xl font-black text-zinc-800 mb-2">需要登录</h3>
                <p class="text-zinc-500 font-medium mb-8">您需要登录才能查看通知</p>
                <button @click="navigateToLogin" class="px-8 py-3.5 bg-[#2f7d4f] hover:bg-[#246a41] text-white font-bold rounded-2xl shadow-lg shadow-[#2f7d4f]/20 transition-all active:scale-95 w-full">
                  立即登录
                </button>
              </div>

              <div v-else class="flex flex-col h-full bg-white rounded-3xl border border-zinc-100 shadow-sm overflow-hidden">
                <!-- Notifications Header -->
                <div class="flex items-center justify-between p-6 border-b border-zinc-100 bg-zinc-50/50">
                  <h2 class="text-xl font-black text-zinc-800 flex items-center gap-3">
                    <div class="w-8 h-8 rounded-xl bg-[#2f7d4f]/10 flex items-center justify-center text-[#2f7d4f]">
                      <Icon name="bell" :size="16" />
                    </div>
                    通知中心
                  </h2>
                  <button @click="toggleNotificationSettings" class="p-2.5 rounded-xl hover:bg-zinc-100 text-zinc-500 hover:text-zinc-800 transition-colors">
                    <Icon name="settings" :size="20" />
                  </button>
                </div>

                <!-- Notifications List -->
                <div class="flex-1 overflow-y-auto p-2 sm:p-6 space-y-3">
                  <div v-if="notificationsLoading" class="flex flex-col items-center justify-center py-20 text-zinc-400 gap-3">
                    <div class="w-8 h-8 rounded-full border-2 border-zinc-200 border-t-[#2f7d4f] animate-spin"></div>
                    <span class="font-medium text-sm">加载中...</span>
                  </div>

                  <div v-else-if="userNotifications.length === 0" class="flex flex-col items-center justify-center py-24 text-center">
                    <div class="w-20 h-20 bg-zinc-50 rounded-3xl flex items-center justify-center text-zinc-300 mb-4 ring-1 ring-zinc-100 shadow-inner">
                      <Icon name="bell-off" :size="32" />
                    </div>
                    <p class="font-bold text-zinc-600 text-lg">暂无通知</p>
                    <p class="text-sm text-zinc-400 mt-1">这里很安静</p>
                  </div>

                  <TransitionGroup name="list" tag="div" class="space-y-3 relative">
                    <div v-for="(notification, index) in userNotifications" :key="notification.id" @click="viewNotification(notification)" :class="[
                      'bg-white rounded-2xl border transition-all cursor-pointer overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-0.5',
                      !notification.read ? 'border-[#2f7d4f]/30 bg-[#2f7d4f]/5' : 'border-zinc-100'
                    ]">
                      <div class="p-4 sm:p-5 flex gap-4">
                        <!-- Icon -->
                        <div :class="[
                          'w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0',
                          notification.type === 'SONG_SELECTED' ? 'bg-[#2f7d4f]/10 text-[#2f7d4f]' :
                          notification.type === 'SONG_PLAYED' ? 'bg-emerald-100 text-emerald-600' :
                          notification.type === 'SONG_VOTED' ? 'bg-[#c28a26]/10 text-[#c28a26]' :
                          notification.type === 'SONG_REJECTED' ? 'bg-red-100 text-red-600' :
                          notification.type === 'COLLABORATION_INVITE' ? 'bg-[#2f7d4f]/10 text-[#2f7d4f]' :
                          notification.type === 'COLLABORATION_RESPONSE' ? 'bg-purple-100 text-purple-600' :
                          'bg-zinc-100 text-zinc-600'
                        ]">
                          <Icon v-if="notification.type === 'SONG_SELECTED'" name="check" :size="20" />
                          <Icon v-else-if="notification.type === 'SONG_PLAYED'" name="play" :size="20" />
                          <Icon v-else-if="notification.type === 'SONG_VOTED'" name="thumbs-up" :size="20" />
                          <Icon v-else-if="notification.type === 'SONG_REJECTED'" name="x-circle" :size="20" />
                          <Icon v-else-if="notification.type === 'COLLABORATION_INVITE'" name="users" :size="20" />
                          <Icon v-else-if="notification.type === 'COLLABORATION_RESPONSE'" name="message-circle" :size="20" />
                          <Icon v-else name="bell" :size="20" />
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                          <div class="flex items-start justify-between gap-2 mb-1">
                            <h4 class="font-bold text-zinc-800 truncate flex items-center gap-2">
                              <span v-if="notification.type === 'SONG_SELECTED'">歌曲已选中</span>
                              <span v-else-if="notification.type === 'SONG_PLAYED'">歌曲已播放</span>
                              <span v-else-if="notification.type === 'SONG_VOTED'">收到新投票</span>
                              <span v-else-if="notification.type === 'SONG_REJECTED'">歌曲被驳回</span>
                              <span v-else-if="notification.type === 'COLLABORATION_INVITE'">联合投稿邀请</span>
                              <span v-else-if="notification.type === 'COLLABORATION_RESPONSE'">联合投稿回复</span>
                              <span v-else>系统通知</span>

                              <span v-if="notification.type === 'COLLABORATION_INVITE' && notification.handled" :class="[
                                'text-[10px] px-2 py-0.5 rounded-md font-bold uppercase tracking-wider',
                                notification.status === 'ACCEPTED' ? 'bg-emerald-100 text-emerald-700' :
                                notification.status === 'INVALID' ? 'bg-zinc-100 text-zinc-600' :
                                'bg-red-100 text-red-700'
                              ]">
                                {{ notification.status === 'ACCEPTED' ? '已接受' : notification.status === 'INVALID' ? '已失效' : '已拒绝' }}
                              </span>

                              <span v-if="!notification.read" class="w-2 h-2 rounded-full bg-[#2f7d4f]"></span>
                            </h4>
                            <span class="text-xs font-medium text-zinc-400 whitespace-nowrap">{{ formatNotificationTime(notification.createdAt) }}</span>
                          </div>
                          
                          <p class="text-sm text-zinc-600 leading-relaxed">{{ notification.message }}</p>

                          <!-- Invite Actions -->
                          <div v-if="notification.type === 'COLLABORATION_INVITE' && !notification.handled" class="mt-4 flex gap-3">
                            <button @click.stop="handleCollaborationReply(notification, true)" :disabled="notification.processing" class="px-4 py-2 bg-[#2f7d4f] hover:bg-[#246a41] text-white text-sm font-bold rounded-xl shadow-sm transition-colors disabled:opacity-50">
                              {{ notification.processing ? '处理中...' : '接受邀请' }}
                            </button>
                            <button @click.stop="handleCollaborationReply(notification, false)" :disabled="notification.processing" class="px-4 py-2 bg-zinc-100 hover:bg-zinc-200 text-zinc-700 text-sm font-bold rounded-xl transition-colors disabled:opacity-50">
                              拒绝
                            </button>
                          </div>
                        </div>

                        <!-- Delete button -->
                        <button @click.stop="deleteNotification(notification.id)" class="opacity-0 group-hover:opacity-100 p-2 text-zinc-400 hover:text-red-500 hover:bg-red-50 rounded-xl transition-all self-center ml-2 hidden sm:block">
                          <Icon name="trash-2" :size="18" />
                        </button>
                      </div>
                    </div>
                  </TransitionGroup>
                </div>

                <!-- Footer / Pagination -->
                <div class="border-t border-zinc-100 bg-zinc-50/50 p-4 shrink-0 space-y-4">
                  <!-- Pagination -->
                  <div v-if="notificationsService.totalCount.value > 0" class="flex flex-col sm:flex-row items-center justify-between gap-4">
                    <span class="text-xs font-medium text-zinc-500">共 {{ notificationsService.totalCount.value }} 条通知， 每页
                      <select v-model="notificationsService.pageSize.value" @change="handlePageSizeChange($event.target.value)" class="bg-white border-zinc-200 rounded px-1 py-0.5 mx-1 outline-none text-xs">
                        <option v-for="opt in pageSizeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                      </select>
                    </span>
                    
                    <div class="flex items-center gap-2">
                      <button @click="notificationsService.prevPage()" :disabled="!notificationsService.hasPrevPage.value || notificationsService.isPaginationLoading.value" class="w-8 h-8 flex items-center justify-center rounded-lg border border-zinc-200 text-zinc-600 disabled:opacity-50 bg-white hover:bg-zinc-50">
                        <Icon name="chevron-left" :size="16" />
                      </button>
                      
                      <div class="flex gap-1">
                         <template v-for="page in getVisiblePages()" :key="page">
                          <button v-if="page !== '...'" @click="notificationsService.goToPage(page)" :disabled="notificationsService.isPaginationLoading.value" :class="[
                            'w-8 h-8 flex items-center justify-center rounded-lg text-sm font-bold transition-colors',
                            page === notificationsService.currentPage.value ? 'bg-[#2f7d4f] text-white' : 'border border-zinc-200 text-zinc-600 bg-white hover:bg-zinc-50'
                          ]">
                            {{ page }}
                          </button>
                          <span v-else class="w-8 h-8 flex items-center justify-center text-zinc-400">...</span>
                        </template>
                      </div>

                      <button @click="notificationsService.nextPage()" :disabled="!notificationsService.hasNextPage.value || notificationsService.isPaginationLoading.value" class="w-8 h-8 flex items-center justify-center rounded-lg border border-zinc-200 text-zinc-600 disabled:opacity-50 bg-white hover:bg-zinc-50">
                        <Icon name="chevron-right" :size="16" />
                      </button>
                    </div>
                  </div>

                  <!-- Actions -->
                  <div v-if="userNotifications.length > 0" class="flex gap-3">
                    <button @click="markAllNotificationsAsRead" :disabled="!hasUnreadNotifications" :class="[
                      'flex-1 py-2.5 rounded-xl font-bold text-sm transition-all',
                      hasUnreadNotifications ? 'bg-white border border-zinc-200 text-zinc-700 hover:bg-zinc-50 shadow-sm' : 'bg-zinc-50 text-zinc-400 cursor-not-allowed'
                    ]">
                      全部标记为已读
                    </button>
                    <button @click="clearAllNotifications" class="px-6 py-2.5 rounded-xl font-bold text-sm bg-red-50 text-red-600 hover:bg-red-100 transition-colors">
                      清空所有
                    </button>
                  </div>
                </div>

                <ConfirmDialog v-model:show="showConfirmDialog" :cancel-text="confirmDialogConfig.cancelText" :confirm-text="confirmDialogConfig.confirmText" :message="confirmDialogConfig.message" :title="confirmDialogConfig.title" :type="confirmDialogConfig.type" @cancel="handleCancelAction" @confirm="handleConfirmAction" />
              </div>
            </div>
          </Transition>
        </div>
      </main>

      <SiteFooter />
    </div>

    <!-- Rules Modal (Redesigned) -->
    <Teleport to="body">
      <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
        <div v-if="showRules" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-zinc-900/40 backdrop-blur-md" @click.self="showRules = false">
          <div class="bg-white w-full max-w-lg rounded-[2rem] overflow-hidden shadow-2xl flex flex-col border border-zinc-100">
            <div class="p-8 pb-6 flex items-center justify-between bg-zinc-50/50 border-b border-zinc-100">
              <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-[#2f7d4f]/10 flex items-center justify-center text-[#2f7d4f] shadow-sm border border-[#2f7d4f]/20">
                  <Icon name="book" :size="24" />
                </div>
                <div>
                  <h3 class="text-xl font-black text-zinc-800 tracking-tight">点歌规则</h3>
                  <p class="text-xs font-semibold text-zinc-500 mt-0.5">投稿前请仔细阅读</p>
                </div>
              </div>
              <button class="w-10 h-10 flex items-center justify-center bg-white hover:bg-zinc-100 text-zinc-400 hover:text-zinc-600 border border-zinc-200 rounded-xl transition-all shadow-sm" @click="showRules = false">
                <Icon name="x" :size="20" />
              </button>
            </div>

            <div class="p-8 space-y-8">
              <div class="space-y-4">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-widest flex items-center gap-2">
                  <Icon name="message-square" :size="14" />
                  投稿须知
                </h4>
                <div v-if="submissionGuidelines" class="text-sm text-zinc-600 leading-relaxed font-medium bg-zinc-50 p-6 rounded-3xl border border-zinc-100" v-html="submissionGuidelines.replace(/\n/g, '<br>')" />
                <div v-else class="space-y-4 bg-zinc-50 p-6 rounded-3xl border border-zinc-100">
                  <div class="flex items-start gap-4 text-sm text-zinc-600 font-medium">
                    <div class="w-6 h-6 rounded-full bg-[#2f7d4f]/10 text-[#2f7d4f] flex items-center justify-center flex-shrink-0 font-black text-xs">1</div>
                    <p class="pt-0.5">投稿时无需加入书名号</p>
                  </div>
                  <div class="flex items-start gap-4 text-sm text-zinc-600 font-medium">
                    <div class="w-6 h-6 rounded-full bg-[#2f7d4f]/10 text-[#2f7d4f] flex items-center justify-center flex-shrink-0 font-black text-xs">2</div>
                    <p class="pt-0.5">除DJ外，其他类型歌曲均接收（包括小语种）</p>
                  </div>
                  <div class="flex items-start gap-4 text-sm text-zinc-600 font-medium">
                    <div class="w-6 h-6 rounded-full bg-[#2f7d4f]/10 text-[#2f7d4f] flex items-center justify-center flex-shrink-0 font-black text-xs">3</div>
                    <p class="pt-0.5">禁止投递含有违规内容的歌曲</p>
                  </div>
                </div>
              </div>

              <div class="space-y-4">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-widest flex items-center gap-2">
                  <Icon name="clock" :size="14" />
                  播放时间
                </h4>
                <div class="bg-gradient-to-br from-[#2f7d4f]/5 to-[#246a41]/10 border border-[#2f7d4f]/10 p-6 rounded-3xl flex items-center gap-5 relative overflow-hidden">
                  <div class="absolute -right-4 -bottom-4 text-[#2f7d4f]/10">
                    <Icon name="headphones" :size="100" />
                  </div>
                  <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center text-[#2f7d4f] shadow-sm border border-[#2f7d4f]/20 relative z-10">
                    <Icon name="play-circle" :size="28" />
                  </div>
                  <div class="relative z-10">
                    <p class="text-lg font-black text-[#1f2a1f] leading-tight">每天夜自修静班前</p>
                    <p class="text-xs text-[#2f7d4f] font-bold uppercase tracking-widest mt-1">
                      PLAYBACK TIME
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div class="p-8 pt-0">
              <button class="w-full px-6 py-4 bg-[#2f7d4f] hover:bg-[#246a41] text-white text-sm font-black rounded-2xl transition-all shadow-lg shadow-[#2f7d4f]/20 active:scale-95 flex items-center justify-center gap-2" @click="showRules = false">
                <Icon name="check-circle" :size="18" />
                我已了解并同意
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

          </ClientOnly>
        </div>

        <!-- 内容区域 -->
        <div class="tab-content-container">
          <!-- 使用Transition组件包裹每个tab-pane -->
          <Transition mode="out-in" name="tab-fade">
            <!-- 播出排期内容 -->
            <div v-if="activeTab === 'schedule'" key="schedule" class="tab-pane schedule-tab-pane">
              <ClientOnly class="full-width">
                <LazySongsScheduleList
                  :error="error"
                  :loading="loading"
                  :schedules="publicSchedules"
                  @semester-change="handleSemesterChange"
                />
              </ClientOnly>
            </div>

            <!-- 歌曲列表内容 -->
            <div v-else-if="activeTab === 'songs'" key="songs" class="tab-pane">
              <div class="song-list-container">
                <ClientOnly>
                  <LazySongsSongList
                    :error="error"
                    :is-admin="isAdmin"
                    :loading="loading"
                    :songs="filteredSongs"
                    @refresh="refreshSongs"
                    @vote="handleVote"
                    @withdraw="handleWithdraw"
                    @cancel-replay="handleCancelReplay"
                    @request-replay="handleRequestReplay"
                    @semester-change="handleSemesterChange"
                  />
                </ClientOnly>
              </div>
            </div>

            <!-- 投稿歌曲内容 -->
            <div v-else-if="activeTab === 'request'" key="request" class="tab-pane request-pane">
              <LazySongsRequestForm
                ref="requestFormRef"
                :loading="loading"
                @request="handleRequest"
                @vote="handleVote"
              />
            </div>

            <!-- 通知内容 -->
            <div
              v-else-if="activeTab === 'notification'"
              key="notification"
              class="tab-pane notification-pane"
            >
              <div v-if="!isClientAuthenticated" class="login-required-container">
                <div class="login-required-content">
                  <div class="login-icon">🔒</div>
                  <h3>需要登录</h3>
                  <p>您需要登录才能查看通知</p>
                  <button class="login-button" @click="navigateToLogin">立即登录</button>
                </div>
              </div>
              <div v-else class="notification-container">
                <!-- 标题和设置按钮 -->
                <div class="notification-header">
                  <h2 class="notification-title">通知中心</h2>
                  <button class="settings-icon" @click="toggleNotificationSettings">
                    <svg
                      fill="none"
                      height="20"
                      stroke="currentColor"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      viewBox="0 0 24 24"
                      width="20"
                      xmlns="http://www.w3.org/2000/svg"
                    >
                      <circle cx="12" cy="12" r="3" />
                      <path
                        d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"
                      />
                    </svg>
                  </button>
                </div>

                <!-- 通知列表 -->
                <div class="notification-list">
                  <div v-if="notificationsLoading" class="loading-indicator">
                    <div class="loading-spinner" />
                    <span>加载中...</span>
                  </div>

                  <div v-else-if="userNotifications.length === 0" class="empty-notification">
                    <div class="empty-icon">
                      <Icon :size="48" color="#6b7280" name="bell" />
                    </div>
                    <p>暂无通知</p>
                  </div>

                  <Transition mode="out-in" name="notification-list-fade">
                    <div
                      v-if="userNotifications.length > 0"
                      :key="notificationsService.currentPage.value"
                      class="notification-items"
                    >
                      <div
                        v-for="(notification, index) in userNotifications"
                        :key="notification.id"
                        :class="{ unread: !notification.read }"
                        :style="{ '--animation-delay': index * 0.1 + 's' }"
                        class="notification-card"
                        @click="viewNotification(notification)"
                      >
                        <div class="notification-card-header">
                          <div class="notification-icon-type">
                            <Icon
                              v-if="notification.type === 'SONG_SELECTED'"
                              :size="20"
                              color="#4f46e5"
                              name="check"
                            />
                            <Icon
                              v-else-if="notification.type === 'SONG_PLAYED'"
                              :size="20"
                              color="#10b981"
                              name="play"
                            />
                            <Icon
                              v-else-if="notification.type === 'SONG_VOTED'"
                              :size="20"
                              color="#f59e0b"
                              name="thumbs-up"
                            />
                            <Icon
                              v-else-if="notification.type === 'SONG_REJECTED'"
                              :size="20"
                              color="#ef4444"
                              name="x-circle"
                            />
                            <Icon
                              v-else-if="notification.type === 'COLLABORATION_INVITE'"
                              :size="20"
                              color="#0B5AFE"
                              name="users"
                            />
                            <Icon
                              v-else-if="notification.type === 'COLLABORATION_RESPONSE'"
                              :size="20"
                              color="#8b5cf6"
                              name="message-circle"
                            />
                            <Icon v-else :size="20" color="#6b7280" name="bell" />
                          </div>
                          <div class="notification-title-row">
                            <div class="notification-title">
                              <span v-if="notification.type === 'SONG_SELECTED'">歌曲已选中</span>
                              <span v-else-if="notification.type === 'SONG_PLAYED'"
                                >歌曲已播放</span
                              >
                              <span v-else-if="notification.type === 'SONG_VOTED'">收到新投票</span>
                              <span v-else-if="notification.type === 'SONG_REJECTED'"
                                >歌曲被驳回</span
                              >
                              <span v-else-if="notification.type === 'COLLABORATION_INVITE'">
                                联合投稿邀请
                                <span
                                  v-if="notification.handled"
                                  :class="[
                                    'status-tag',
                                    notification.status === 'ACCEPTED'
                                      ? 'accepted'
                                      : notification.status === 'INVALID'
                                        ? 'invalid'
                                        : 'rejected'
                                  ]"
                                >
                                  {{
                                    notification.status === 'ACCEPTED'
                                      ? '- 已接受'
                                      : notification.status === 'INVALID'
                                        ? '- 已失效'
                                        : '- 已拒绝'
                                  }}
                                </span>
                              </span>
                              <span v-else-if="notification.type === 'COLLABORATION_RESPONSE'"
                                >联合投稿回复</span
                              >
                              <span v-else>系统通知</span>
                              <span v-if="!notification.read" class="unread-indicator" />
                            </div>
                            <div class="notification-time">
                              {{ formatNotificationTime(notification.createdAt) }}
                            </div>
                          </div>
                        </div>
                        <div class="notification-card-body">
                          <div class="notification-text">{{ notification.message }}</div>

                          <!-- 联合投稿邀请操作按钮 -->
                          <div
                            v-if="
                              notification.type === 'COLLABORATION_INVITE' && !notification.handled
                            "
                            class="invite-actions"
                          >
                            <button
                              :disabled="notification.processing"
                              class="action-button accept-btn"
                              @click.stop="handleCollaborationReply(notification, true)"
                            >
                              {{ notification.processing ? '处理中...' : '接受邀请' }}
                            </button>
                            <button
                              :disabled="notification.processing"
                              class="action-button reject-btn"
                              @click.stop="handleCollaborationReply(notification, false)"
                            >
                              拒绝
                            </button>
                          </div>
                        </div>
                        <div class="notification-card-actions">
                          <button
                            class="action-button delete"
                            title="删除"
                            @click.stop="deleteNotification(notification.id)"
                          >
                            <svg
                              fill="none"
                              height="16"
                              stroke="currentColor"
                              stroke-linecap="round"
                              stroke-linejoin="round"
                              stroke-width="2"
                              viewBox="0 0 24 24"
                              width="16"
                              xmlns="http://www.w3.org/2000/svg"
                            >
                              <polyline points="3 6 5 6 21 6" />
                              <path
                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"
                              />
                            </svg>
                            <span>删除</span>
                          </button>
                        </div>
                      </div>
                    </div>
                  </Transition>
                </div>

                <!-- 分页控件 -->
                <div
                  v-if="notificationsService.totalCount.value > 0"
                  class="notification-pagination"
                >
                  <div class="pagination-info">
                    <span class="pagination-text">
                      共 {{ notificationsService.totalCount.value }} 条通知， 第
                      {{ notificationsService.currentPage.value }} /
                      {{ notificationsService.totalPages.value }} 页
                    </span>
                  </div>

                  <div class="pagination-controls">
                    <!-- 每页显示数量选择器 -->
                    <div class="page-size-selector">
                      <label for="pageSize">每页显示：</label>
                      <CustomSelect
                        id="pageSize"
                        :model-value="notificationsService.pageSize.value"
                        :options="pageSizeOptions"
                        class="page-size-custom-select"
                        @update:model-value="handlePageSizeChange"
                      />
                    </div>

                    <!-- 页码导航 -->
                    <div class="page-navigation">
                      <button
                        :disabled="
                          !notificationsService.hasPrevPage.value ||
                          notificationsService.isPaginationLoading.value
                        "
                        class="page-nav-button"
                        title="上一页"
                        @click="notificationsService.prevPage()"
                      >
                        <svg
                          fill="none"
                          height="16"
                          stroke="currentColor"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          viewBox="0 0 24 24"
                          width="16"
                          xmlns="http://www.w3.org/2000/svg"
                        >
                          <polyline points="15 18 9 12 15 6" />
                        </svg>
                      </button>

                      <!-- 页码按钮 -->
                      <div class="page-numbers">
                        <template v-for="page in getVisiblePages()" :key="page">
                          <button
                            v-if="page !== '...'"
                            :class="[
                              'page-number-button',
                              { active: page === notificationsService.currentPage.value }
                            ]"
                            :disabled="notificationsService.isPaginationLoading.value"
                            @click="notificationsService.goToPage(page)"
                          >
                            {{ page }}
                          </button>
                          <span v-else class="page-ellipsis">...</span>
                        </template>
                      </div>

                      <button
                        :disabled="
                          !notificationsService.hasNextPage.value ||
                          notificationsService.isPaginationLoading.value
                        "
                        class="page-nav-button"
                        title="下一页"
                        @click="notificationsService.nextPage()"
                      >
                        <svg
                          fill="none"
                          height="16"
                          stroke="currentColor"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          viewBox="0 0 24 24"
                          width="16"
                          xmlns="http://www.w3.org/2000/svg"
                        >
                          <polyline points="9 18 15 12 9 6" />
                        </svg>
                      </button>
                    </div>
                  </div>

                  <!-- 分页加载状态 -->
                  <div
                    v-if="notificationsService.isPaginationLoading.value"
                    class="pagination-loading"
                  >
                    <div class="loading-spinner" />
                    <span>加载中...</span>
                  </div>
                </div>

                <!-- 底部操作按钮 -->
                <div v-if="userNotifications.length > 0" class="notification-actions-bar">
                  <button
                    :class="{ disabled: !hasUnreadNotifications }"
                    :disabled="!hasUnreadNotifications"
                    class="action-button-large"
                    @click="markAllNotificationsAsRead"
                  >
                    全部标记为已读
                  </button>
                  <button class="action-button-large danger" @click="clearAllNotifications">
                    清空所有消息
                  </button>
                </div>

                <!-- 确认对话框 -->
                <ConfirmDialog
                  v-model:show="showConfirmDialog"
                  :cancel-text="confirmDialogConfig.cancelText"
                  :confirm-text="confirmDialogConfig.confirmText"
                  :message="confirmDialogConfig.message"
                  :title="confirmDialogConfig.title"
                  :type="confirmDialogConfig.type"
                  @cancel="handleCancelAction"
                  @confirm="handleConfirmAction"
                />
              </div>
            </div>
          </Transition>
        </div>
      </div>

      <!-- 页脚信息显示 -->
      <SiteFooter />
    </div>

    <!-- 规则弹窗 -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
      >
        <div
          v-if="showRules"
          class="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm"
          @click.self="showRules = false"
        >
          <div
            class="bg-zinc-900 border border-zinc-800 w-full max-w-lg rounded-3xl overflow-hidden shadow-2xl flex flex-col"
          >
            <div class="p-8 pb-4 flex items-center justify-between">
              <div>
                <h3 class="text-xl font-black text-zinc-100 tracking-tight flex items-center gap-3">
                  <div
                    class="w-10 h-10 rounded-2xl bg-blue-600/10 flex items-center justify-center text-blue-500"
                  >
                    <Icon name="bell" :size="20" />
                  </div>
                  点歌规则
                </h3>
                <p class="text-xs text-zinc-500 mt-1 ml-13">投稿前请仔细阅读以下规则</p>
              </div>
              <button
                class="p-3 bg-zinc-800/50 hover:bg-zinc-800 text-zinc-500 hover:text-zinc-200 rounded-2xl transition-all"
                @click="showRules = false"
              >
                <Icon name="x" :size="20" />
              </button>
            </div>

            <div class="p-8 pt-4 space-y-8">
              <div class="rules-group space-y-4">
                <h4
                  class="text-[10px] font-black text-zinc-500 uppercase tracking-widest flex items-center gap-2"
                >
                  <Icon name="message-circle" :size="12" />
                  投稿须知
                </h4>
                <div
                  v-if="submissionGuidelines"
                  class="text-sm text-zinc-400 leading-relaxed font-medium bg-zinc-950/50 p-6 rounded-3xl border border-zinc-800/50"
                  v-html="submissionGuidelines.replace(/\n/g, '<br>')"
                />
                <div
                  v-else
                  class="space-y-3 bg-zinc-950/50 p-6 rounded-3xl border border-zinc-800/50"
                >
                  <div class="flex gap-3 text-sm text-zinc-400 font-medium">
                    <span class="text-blue-500 font-black">01</span>
                    <p>投稿时无需加入书名号</p>
                  </div>
                  <div class="flex gap-3 text-sm text-zinc-400 font-medium">
                    <span class="text-blue-500 font-black">02</span>
                    <p>除DJ外，其他类型歌曲均接收（包括小语种）</p>
                  </div>
                  <div class="flex gap-3 text-sm text-zinc-400 font-medium">
                    <span class="text-blue-500 font-black">03</span>
                    <p>禁止投递含有违规内容的歌曲</p>
                  </div>
                </div>
              </div>

              <div class="rules-group space-y-4">
                <h4
                  class="text-[10px] font-black text-zinc-500 uppercase tracking-widest flex items-center gap-2"
                >
                  <Icon name="calendar" :size="12" />
                  播放时间
                </h4>
                <div
                  class="bg-blue-600/10 border border-blue-500/20 p-6 rounded-3xl flex items-center gap-4"
                >
                  <div
                    class="w-12 h-12 rounded-2xl bg-blue-600 flex items-center justify-center text-white shadow-lg shadow-blue-900/40"
                  >
                    <Icon name="clock" :size="24" />
                  </div>
                  <div>
                    <p class="text-sm font-black text-zinc-100">每天夜自修静班前</p>
                    <p class="text-[10px] text-zinc-500 font-bold uppercase tracking-widest mt-0.5">
                      PLAYBACK TIME
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div class="p-8 pt-0">
              <button
                class="w-full px-6 py-4 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-black rounded-2xl transition-all uppercase tracking-widest shadow-lg active:scale-95"
                @click="showRules = false"
              >
                我知道了
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import logo from '~~/public/images/logo.svg'
import Icon from '~/components/UI/Icon.vue'
import ConfirmDialog from '~/components/UI/ConfirmDialog.vue'

import { useNotifications } from '~/composables/useNotifications'
import { useSiteConfig } from '~/composables/useSiteConfig'
import CustomSelect from '~/components/UI/Common/CustomSelect.vue'

// 获取运行时配置
const config = useRuntimeConfig()
const router = useRouter()

// 站点配置
const {
  siteTitle,
  description: siteDescription,
  guidelines: submissionGuidelines,
  icp: icpNumber,
  schoolLogoHomeUrl,
  initSiteConfig
} = useSiteConfig()

const auth = useAuth()

const isClientAuthenticated = computed(() => auth?.isAuthenticated?.value || false)
const isAdmin = computed(() => auth?.isAdmin?.value || false)
const user = computed(() => auth?.user?.value || null)

const roleName = computed(() => {
  const role = user.value?.role
  const map = {
    ADMIN: '管理员',
    SUPER_ADMIN: '超级管理员',
    SONG_ADMIN: '审歌员',
    USER: '普通用户'
  }
  return map[role] || '管理员'
})

const userClassInfo = computed(() => {
  if (user.value?.grade && user.value?.class) {
    return `${user.value.grade} ${user.value.class}`
  }
  return '同学'
})

const songs = useSongs()
// 立即初始化通知服务，避免时序问题
const notificationsService = useNotifications()
const unreadNotificationCount = ref(0)

// 模拟数据初始值
const songCount = ref(0)
const scheduleCount = ref(0)
const isRequestOpen = ref(true)

// 弹窗状态
const showRequestModal = ref(false)
const showRules = ref(false)
const showUserActions = ref(false)
const avatarError = ref(false)

const toggleUserActions = (event) => {
  event.stopPropagation()
  showUserActions.value = !showUserActions.value
}

// 监听用户头像变化，重置错误状态
watch(
  () => user.value?.avatar,
  () => {
    avatarError.value = false
  }
)

// 点击外部关闭下拉菜单
const handleClickOutside = (event) => {
  if (showUserActions.value) {
    const dropdown = document.querySelector('.user-actions-dropdown')
    const avatar = document.querySelector('.user-avatar-wrapper')
    if (dropdown && !dropdown.contains(event.target) && !avatar.contains(event.target)) {
      showUserActions.value = false
    }
  }
}

onMounted(() => {
  window.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  window.removeEventListener('click', handleClickOutside)
})

// 标签页状态
const activeTab = ref('schedule') // 默认显示播出排期

const tabOrder = ['schedule', 'songs', 'request', 'notification']
const activeIndex = computed(() => {
  const index = tabOrder.indexOf(activeTab.value)
  return index === -1 ? 0 : index
})

// 通知按钮强制更新相关
const notificationTabRef = ref(null)
const notificationTabKey = ref(0)

let refreshInterval = null

// 添加通知相关变量
const userNotifications = computed(() => notificationsService?.notifications?.value || [])
const notificationsLoading = computed(() => notificationsService?.loading?.value || false)
const hasUnreadNotifications = computed(() => {
  // 确保notificationsService已初始化且有unreadCount
  if (!notificationsService || !notificationsService.unreadCount) {
    return false
  }
  const unreadCount = notificationsService.unreadCount.value
  return unreadCount > 0
})
const showNotificationSettings = ref(false)

const pageSizeOptions = [
  { label: '5条', value: 5 },
  { label: '10条', value: 10 },
  { label: '20条', value: 20 },
  { label: '50条', value: 50 }
]

const notificationSettings = ref({
  songSelectedNotify: true,
  songPlayedNotify: true,
  songVotedNotify: true,
  songVotedThreshold: 1,
  systemNotify: true,
  refreshInterval: 60
})

// 跳转到通知设置页面
const toggleNotificationSettings = () => {
  navigateTo('/notification-settings')
}

// 获取通知设置
const fetchNotificationSettings = async () => {
  if (notificationsService) {
    await notificationsService.fetchNotificationSettings()
    if (notificationsService.settings.value) {
      notificationSettings.value = {
        songSelectedNotify: notificationsService.settings.value.songSelectedNotify,
        songPlayedNotify: notificationsService.settings.value.songPlayedNotify,
        songVotedNotify: notificationsService.settings.value.songVotedNotify,
        songVotedThreshold: notificationsService.settings.value.songVotedThreshold || 1,
        systemNotify: notificationsService.settings.value.systemNotify,
        refreshInterval: notificationsService.settings.value.refreshInterval || 60
      }
    }
  }
}

// 保存通知设置
const saveNotificationSettings = async () => {
  if (notificationsService) {
    await notificationsService.updateNotificationSettings(notificationSettings.value)

    // 如果在首页，更新刷新间隔
    if (typeof setupRefreshInterval === 'function') {
      setupRefreshInterval()
    }
  }
}

// 加载通知
const loadNotifications = async () => {
  if (isClientAuthenticated.value && notificationsService) {
    try {
      await notificationsService.fetchNotifications()
    } catch (error) {
      console.error('[通知获取] 加载通知失败:', error)
    }
  }
}

// 标记通知为已读
const markNotificationAsRead = async (id) => {
  if (notificationsService) {
    await notificationsService.markAsRead(id)
  }
}

// 标记所有通知为已读
const markAllNotificationsAsRead = async () => {
  try {
    if (notificationsService) {
      await notificationsService.markAllAsRead()
    }
  } catch (error) {
    console.error('[通知操作] 标记所有通知为已读失败:', error)
  }
}

// 删除通知
const deleteNotification = async (id) => {
  pendingAction.value = 'delete'
  pendingId.value = id
  confirmDialogConfig.value = {
    title: '删除消息',
    message: '确定要删除此消息吗？',
    type: 'warning',
    confirmText: '删除',
    cancelText: '取消'
  }
  showConfirmDialog.value = true
}

// 清空所有通知
const clearAllNotifications = async () => {
  pendingAction.value = 'clearAll'
  confirmDialogConfig.value = {
    title: '清空所有消息',
    message: '确定要清空所有消息吗？此操作不可撤销。',
    type: 'danger',
    confirmText: '清空',
    cancelText: '取消'
  }
  showConfirmDialog.value = true
}

// 确认对话框相关状态
const showConfirmDialog = ref(false)
const confirmDialogConfig = ref({
  title: '',
  message: '',
  type: 'warning',
  confirmText: '确定',
  cancelText: '取消'
})
const pendingAction = ref('')
const pendingId = ref(null)

// 处理确认操作
const handleConfirmAction = async () => {
  if (notificationsService) {
    if (pendingAction.value === 'delete') {
      await notificationsService.deleteNotification(pendingId.value)
      pendingId.value = null
    } else if (pendingAction.value === 'clearAll') {
      await notificationsService.clearAllNotifications()
    }
  }
  showConfirmDialog.value = false
  pendingAction.value = ''
}

// 处理取消操作
const handleCancelAction = () => {
  showConfirmDialog.value = false
  pendingAction.value = ''
  pendingId.value = null
}

// 分页相关方法
const handlePageSizeChange = async (newSize) => {
  const size = parseInt(newSize)
  if (notificationsService) {
    await notificationsService.changePageSize(size)
  }
}

// 获取可见的页码列表
const getVisiblePages = () => {
  if (!notificationsService) return []

  const currentPage = notificationsService.currentPage.value
  const totalPages = notificationsService.totalPages.value
  const pages = []

  if (totalPages <= 7) {
    // 总页数少于等于7页，显示所有页码
    for (let i = 1; i <= totalPages; i++) {
      pages.push(i)
    }
  } else {
    // 总页数大于7页，显示省略号
    if (currentPage <= 4) {
      // 当前页在前面
      for (let i = 1; i <= 5; i++) {
        pages.push(i)
      }
      pages.push('...')
      pages.push(totalPages)
    } else if (currentPage >= totalPages - 3) {
      // 当前页在后面
      pages.push(1)
      pages.push('...')
      for (let i = totalPages - 4; i <= totalPages; i++) {
        pages.push(i)
      }
    } else {
      // 当前页在中间
      pages.push(1)
      pages.push('...')
      for (let i = currentPage - 1; i <= currentPage + 1; i++) {
        pages.push(i)
      }
      pages.push('...')
      pages.push(totalPages)
    }
  }

  return pages
}

// 格式化通知时间
const formatNotificationTime = (timeString) => {
  const date = new Date(timeString)
  const now = new Date()
  const diff = now.getTime() - date.getTime()

  // 小于1分钟
  if (diff < 60000) {
    return '刚刚'
  }

  // 小于1小时
  if (diff < 3600000) {
    return `${Math.floor(diff / 60000)}分钟前`
  }

  // 小于24小时
  if (diff < 86400000) {
    return `${Math.floor(diff / 3600000)}小时前`
  }

  // 小于30天
  if (diff < 2592000000) {
    return `${Math.floor(diff / 86400000)}天前`
  }

  // 大于30天，显示具体日期
  return `${date.getFullYear()}-${(date.getMonth() + 1).toString().padStart(2, '0')}-${date.getDate().toString().padStart(2, '0')}`
}

// 监听标签页切换，如果切换到通知标签页，加载通知
watch(activeTab, (newTab) => {
  if (newTab === 'notification') {
    loadNotifications()
  }
})

// 监听登录状态变化，确保通知标签页状态立即更新
watch(
  () => auth?.isAuthenticated?.value,
  (newAuthState) => {
    if (newAuthState) {
      // 用户刚登录，立即加载通知相关数据
      nextTick(() => {
        if (notificationsService) {
          loadNotifications()
          fetchNotificationSettings()
        }
      })
    }
  },
  { immediate: false }
)

// 初始化时如果已经在通知标签页，则加载通知
onMounted(() => {
  if (activeTab.value === 'notification') {
    loadNotifications()
  }
})

// 获取当前日期和星期
const getCurrentDate = () => {
  const now = new Date()
  const year = now.getFullYear()
  const month = now.getMonth() + 1
  const date = now.getDate()
  const weekDays = ['日', '一', '二', '三', '四', '五', '六']
  const weekDay = weekDays[now.getDay()]

  return `${year}年${month}月${date}日 周${weekDay}`
}

// RequestForm组件引用
const requestFormRef = ref(null)

// 旧的showNotification函数已移除，使用全局通知系统

// 更新歌曲数量统计（优化版本，避免重复请求）
const updateSongCounts = async (semester = null) => {
  try {
    // 更新排期歌曲数量
    const schedules = songs?.publicSchedules?.value || []
    scheduleCount.value = schedules.length

    // 更新总歌曲数量
    if (isClientAuthenticated.value && songs?.songs?.value) {
      // 已登录用户：使用完整歌曲列表
      songCount.value = songs.songs.value.length
    } else {
      // 未登录用户：使用缓存的歌曲总数
      songCount.value = songs?.songCount?.value || 0
    }
  } catch (e) {
    console.error('更新歌曲统计失败', e)
  }
}

// 监听siteTitle变化，确保首页title正确设置
watch(
  siteTitle,
  (newSiteTitle) => {
    if (typeof document !== 'undefined' && newSiteTitle) {
      document.title = `首页 | ${newSiteTitle}`
    }
  },
  { immediate: true }
)

// 在组件挂载后初始化认证和歌曲（只会在客户端执行）
onMounted(async () => {
  // 初始化站点配置
  await initSiteConfig()

  // 初始化认证状态并获取用户信息
  const currentUser = await auth.initAuth()

  // 监听登录状态变化，确保UI立即响应
  watch(
    () => auth?.isAuthenticated?.value,
    async (newAuthState, oldAuthState) => {
      if (newAuthState && !oldAuthState) {
        // 用户刚刚登录成功，立即更新相关数据
        console.log('用户登录状态变化，开始强制更新通知按钮')

        // 方法1: 更新key值强制重新渲染
        notificationTabKey.value++

        await nextTick()

        // 方法2: 直接操作ref元素
        if (notificationTabRef.value) {
          notificationTabRef.value.classList.remove('disabled')
          notificationTabRef.value.style.opacity = '1'
          notificationTabRef.value.style.cursor = 'pointer'
          notificationTabRef.value.style.pointerEvents = 'auto'
        }

        // 方法3: 强制触发响应式更新
        await nextTick(() => {
          // 强制重新计算isClientAuthenticated
          if (typeof window !== 'undefined') {
            // 直接操作DOM确保样式立即更新
            const notificationTab = document.querySelector('.section-tab[data-tab="notification"]')
            if (notificationTab) {
              notificationTab.classList.remove('disabled')
              // 强制重新应用class绑定
              notificationTab.style.opacity = '1'
              notificationTab.style.cursor = 'pointer'
              notificationTab.style.pointerEvents = 'auto'
            }
          }
        })

        // 方法4: 再次更新key值确保完全重新渲染
        await nextTick()
        notificationTabKey.value++

        // 方法5: 再次使用nextTick确保Vue响应式系统完全更新
        await nextTick()

        console.log('通知按钮强制更新完成')

        await Promise.all([loadNotifications(), fetchNotificationSettings()])
      }
    },
    { immediate: false, flush: 'post' }
  )

  // 确保title正确设置
  if (typeof document !== 'undefined' && siteTitle.value) {
    document.title = `首页 | ${siteTitle.value}`
  }

  // 通知服务已在setup阶段初始化，这里不需要重复初始化

  // 优化数据加载流程：根据用户状态加载不同数据
  if (isClientAuthenticated.value) {
    // 已登录用户：并行加载完整歌曲列表、公共排期、通知和设置
    await Promise.all([
      songs.fetchSongs(),
      songs.fetchPublicSchedules(),
      loadNotifications(),
      fetchNotificationSettings()
    ])

    // 检查用户是否需要修改密码并显示提示
    await checkPasswordChangeRequired(currentUser)
  } else {
    // 未登录用户：并行加载歌曲总数和公共排期
    await Promise.all([songs.fetchSongCount(), songs.fetchPublicSchedules()])
  }

  // 更新统计数据（基于已加载的缓存数据）
  await updateSongCounts()

  // 设置智能定时刷新（只刷新过期或即将过期的数据）
  const setupRefreshInterval = () => {
    // 清除之前的定时器
    if (refreshInterval) {
      clearInterval(refreshInterval)
    }

    // 获取用户设置的刷新间隔（秒），默认60秒
    const intervalSeconds = notificationSettings.value.refreshInterval || 60
    const intervalMs = intervalSeconds * 1000

    console.log(`设置智能刷新间隔: ${intervalSeconds}秒`)

    refreshInterval = setInterval(async () => {
      try {
        // 定期刷新数据
        if (isClientAuthenticated.value) {
          // 已登录用户：刷新歌曲列表、公共排期和通知
          await Promise.allSettled([
            songs.fetchSongs(true),
            songs.fetchPublicSchedules(true),
            loadNotifications()
          ])
        } else {
          // 未登录用户：刷新公共排期和歌曲总数
          await Promise.allSettled([songs.fetchPublicSchedules(true), songs.fetchSongCount()])
        }

        // 更新统计数据
        await updateSongCounts()
      } catch (error) {
        console.error('定期刷新失败:', error)
      }
    }, intervalMs)
  }

  // 初始设置刷新间隔
  setupRefreshInterval()

  // 监听通知
  if (songs.notification && songs.notification.value) {
    watch(songs.notification, (newVal) => {
      if (newVal.show) {
        showNotification(newVal.message, newVal.type)
      }
    })
  }
})

// 组件卸载时清除定时器
onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})

// 实时计算歌曲总数
const realSongCount = computed(() => {
  return songs?.visibleSongs?.value?.length || 0
})

// 使用计算属性安全地访问数据
const publicSchedules = computed(() => songs?.publicSchedules?.value || [])
const allSongs = computed(() => songs?.visibleSongs?.value || [])
const filteredSongs = computed(() => {
  // 返回所有歌曲，但将已播放的歌曲排在最后
  if (allSongs.value && allSongs.value.length > 0) {
    const unplayedSongs = allSongs.value.filter((song) => !song.played)
    const playedSongs = allSongs.value.filter((song) => song.played)
    return [...unplayedSongs, ...playedSongs]
  }
  return []
})
const loading = computed(() => songs?.loading?.value || false)
const error = computed(() => songs?.error?.value || '')

// 处理学校logo的HTTP/HTTPS代理
const proxiedSchoolLogoUrl = computed(() => {
  if (!schoolLogoHomeUrl.value || !schoolLogoHomeUrl.value.trim()) {
    return ''
  }

  const logoUrl = schoolLogoHomeUrl.value.trim()

  // 如果是HTTP链接，通过代理访问
  if (logoUrl.startsWith('http://')) {
    return `/api/proxy/image?url=${encodeURIComponent(logoUrl)}`
  }

  // HTTPS链接或相对路径直接返回
  return logoUrl
})

// 处理投稿请求
const handleRequest = async (songData) => {
  if (!auth || !isClientAuthenticated.value) {
    if (window.$showNotification) {
      window.$showNotification('需要登录才能投稿歌曲', 'error')
    }
    showRequestModal.value = false
    return false
  }

  try {
    console.log('处理歌曲请求:', songData.title)
    // 直接传递整个songData对象，确保JSON格式正确
    const result = await songs.requestSong(songData)
    if (result) {
      // 显示投稿成功通知
      if (window.$showNotification) {
        window.$showNotification(`《${songData.title} - ${songData.artist}》投稿成功！`, 'success')
      }

      // 强制刷新歌曲列表
      console.log('投稿成功，刷新歌曲列表')
      await refreshSongs()

      // 刷新投稿状态
      if (requestFormRef.value && requestFormRef.value.refreshSubmissionStatus) {
        await requestFormRef.value.refreshSubmissionStatus()
      }

      // 如果当前在歌曲列表页，自动切换到该页面
      if (activeTab.value !== 'songs') {
        setTimeout(() => {
          handleTabClick('songs')
        }, 500)
      }

      return true
    }
    return false
  } catch (err) {
    if (window.$showNotification) {
      window.$showNotification(err.message || '点歌失败', 'error')
    }
    return false
  }
}

// 处理投票
const handleVote = async (song) => {
  if (!isClientAuthenticated.value) {
    showNotification('请先登录后再投票', 'error')
    return
  }

  try {
    if (!songs) return

    // 调用投票API - 通知已在composable中处理
    // 检查是否是取消投票请求
    if (song.unvote) {
      // 传递完整对象以支持撤销投票功能
      await songs.voteSong(song)
    } else {
      // 保持向后兼容，传递ID
      await songs.voteSong(song.id)
    }

    // 静默刷新歌曲列表以获取最新状态，但不影响当前视图
    setTimeout(() => {
      songs.refreshSongsSilent().catch((err) => {
        console.error('刷新歌曲列表失败', err)
      })
    }, 500)
  } catch (err) {
    // 不做任何处理，因为useSongs中已经处理了错误提示
    console.log('API错误已在useSongs中处理')
  }
}

const handleCancelReplay = async (song) => {
  if (!isClientAuthenticated.value) {
    showNotification('请先登录才能取消重播申请', 'error')
    return
  }

  try {
    if (!songs) return
    await songs.withdrawReplay(song.id)
    updateSongCounts()
  } catch (err) {
    console.log('API错误已在useSongs中处理')
  }
}

const handleRequestReplay = async (song) => {
  if (!isClientAuthenticated.value) {
    showNotification('请先登录才能申请重播', 'error')
    return
  }

  try {
    if (!songs) return
    await songs.requestReplay(song.id)
    updateSongCounts()
  } catch (err) {
    console.log('API错误已在useSongs中处理')
  }
}

// 处理撤回投稿
const handleWithdraw = async (song) => {
  if (!isClientAuthenticated.value) {
    showNotification('请先登录才能撤回投稿', 'error')
    return
  }

  try {
    if (!songs) return

    // 调用撤回API - 通知已在composable中处理
    await songs.withdrawSong(song.id)
    // 更新计数
    updateSongCounts()
  } catch (err) {
    // 不做任何处理，因为useSongs中已经处理了错误提示
    console.log('API错误已在useSongs中处理')
  }
}

// 刷新歌曲列表（优化版本）
const refreshSongs = async () => {
  try {
    if (isClientAuthenticated.value) {
      await songs.fetchSongs(false, undefined, true) // forceRefresh=true
    } else {
      await songs.fetchPublicSchedules(false, undefined, true) // forceRefresh=true
    }

    updateSongCounts()
  } catch (err) {
    console.error('刷新歌曲列表失败', err)
  }
}

// 处理学期变化（前端过滤版本）
const handleSemesterChange = async (semester) => {
  try {
    // 通过事件总线通知SongList组件进行前端过滤
    // 使用nextTick确保事件在DOM更新后触发
    await nextTick()

    // 触发自定义事件，通知所有监听的组件
    const event = new CustomEvent('semester-filter-change', {
      detail: { semester }
    })
    window.dispatchEvent(event)

    console.log('学期切换事件已发送:', semester)

    // 更新歌曲计数（基于当前已有数据）
    await updateSongCounts(semester)
  } catch (err) {
    console.error('切换学期失败', err)
  }
}

// 更新通知数量 - 可以保留这个函数但不再调用
const updateNotificationCount = async () => {
  // 函数保留但不再使用
}

// 处理登出
const handleLogout = () => {
  if (auth) {
    auth.logout()
  }
}

// 处理进入后台的点击动画
const handleDashboardClick = (event) => {
  const button = event.currentTarget
  button.classList.add('clicking')

  // 添加涟漪效果
  const ripple = document.createElement('span')
  ripple.classList.add('ripple')
  button.appendChild(ripple)

  setTimeout(() => {
    button.classList.remove('clicking')
    ripple.remove()
  }, 300)
}

// 添加查看通知并标记为已读
const viewNotification = async (notification) => {
  if (!notification.read) {
    await notificationsService.markAsRead(notification.id)
  }
}

// 处理联合投稿回复
const handleCollaborationReply = async (notification, accept) => {
  if (notification.processing) return
  notification.processing = true

  try {
    await $fetch('/api/songs/collaborators/reply', {
      method: 'POST',
      body: {
        songId: notification.songId,
        accept
      }
    })

    // 标记为已处理
    notification.handled = true
    notification.status = accept ? 'ACCEPTED' : 'REJECTED'
    notification.repliedAt = new Date()
    // notification.message += accept ? ' (已接受)' : ' (已拒绝)'

    if (window.$showNotification) {
      window.$showNotification(accept ? '已接受联合投稿邀请' : '已拒绝联合投稿邀请', 'success')
    }

    // 标记通知为已读
    await markNotificationAsRead(notification.id)

    // 刷新歌曲列表
    refreshSongs()

    // 刷新通知列表
    await loadNotifications()
  } catch (error) {
    console.error('处理联合投稿邀请失败:', error)
    if (window.$showNotification) {
      window.$showNotification(error.statusMessage || '操作失败', 'error')
    }
  } finally {
    notification.processing = false
  }
}

// 格式化刷新间隔
const formatRefreshInterval = (seconds) => {
  if (seconds < 60) {
    return `${seconds}秒`
  } else {
    const minutes = Math.floor(seconds / 60)
    const remainingSeconds = seconds % 60
    return remainingSeconds > 0 ? `${minutes}分${remainingSeconds}秒` : `${minutes}分钟`
  }
}

// 波纹效果指令
const vRipple = {
  mounted(el) {
    el.addEventListener('click', (e) => {
      const rect = el.getBoundingClientRect()
      const x = e.clientX - rect.left
      const y = e.clientY - rect.top

      const ripple = document.createElement('span')
      ripple.className = 'ripple-effect'
      ripple.style.left = `${x}px`
      ripple.style.top = `${y}px`

      el.appendChild(ripple)

      setTimeout(() => {
        ripple.remove()
      }, 600) // 与CSS动画时间一致
    })
  }
}

// 处理标签点击事件，添加动画效果
const handleTabClick = (tab) => {
  activeTab.value = tab
}

// 添加导航到登录页面的方法
const navigateToLogin = () => {
  router.push('/login')
}

// 显示登录提示
const showLoginNotice = () => {
  if (window.$showNotification) {
    window.$showNotification('需要登录才能查看通知', 'info')
  }
}

// 检查用户是否需要修改密码
const checkPasswordChangeRequired = async (user = null) => {
  try {
    // 使用传入的用户信息或当前认证状态中的用户信息
    const currentUser = user || auth?.user?.value

    if (currentUser && currentUser.requirePasswordChange) {
      // 延迟1秒显示通知，确保页面加载完成
      setTimeout(() => {
        if (window.$showNotification) {
          window.$showNotification(
            '为了您的账户安全，建议您修改密码。您可以点击右上角的"修改密码"按钮进行修改。',
            'info',
            true,
            8000 // 显示8秒
          )
        }
      }, 1000)
    }
  } catch (error) {
    console.error('检查密码修改状态失败:', error)
  }
}

// 旧的showToast函数已移除，使用全局通知系统

// 添加未读通知计数
// 之前已声明了unreadNotificationCount，这里对其进行增强
if (
  notificationsService &&
  notificationsService.unreadCount &&
  notificationsService.unreadCount.value
) {
  const count = notificationsService.unreadCount.value
  unreadNotificationCount.value = count
}
</script>

<style scoped>
/* 隐藏滚动条但保留滚动功能 */
.hide-scrollbar {
  -ms-overflow-style: none; /* IE and Edge */
  scrollbar-width: none; /* Firefox */
}
.hide-scrollbar::-webkit-scrollbar {
  display: none; /* Chrome, Safari and Opera */
}

/* 动画和过渡效果 */
.animate-fade-in-up {
  animation: fadeInUp 0.6s ease-out forwards;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* 渐变列表过渡 */
.list-enter-active,
.list-leave-active {
  transition: all 0.4s ease;
}
.list-enter-from,
.list-leave-to {
  opacity: 0;
  transform: translateX(-20px);
}

/* User actions dropdown custom fade */
.dropdown-fade-enter-active,
.dropdown-fade-leave-active {
  transition: opacity 0.2s, transform 0.2s;
}
.dropdown-fade-enter-from,
.dropdown-fade-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}
</style>

